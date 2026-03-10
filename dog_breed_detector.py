"""
PawDetect — Dog Breed Detector (Fixed & Cleaned)
=================================================
Uses MobileNetV2 (ImageNet) to identify dog breeds from an image.

ROOT CAUSE FIX for "Could not parse CNN output":
    TensorFlow prints model-download progress bars and init logs directly
    to stdout EVEN with all env flags set, because Keras uses tqdm and
    its own print() calls during the first model load (weights download).

    This version uses a subprocess-based architecture:
      • The heavy TF work runs in a child process with stdout fully
        replaced by /dev/null at the OS level (fd 1 → /dev/null).
      • Results are passed back via a temp JSON file, NOT stdout.
      • The parent process then reads the file and prints clean JSON.

    This is the ONLY reliable way to guarantee stdout purity when
    TensorFlow downloads weights for the first time.

Usage:
    python dog_breed_detector.py <image_path>
    python dog_breed_detector.py <image_path> --debug

Output (dog detected):
    { "success": true, "breed": "...", "confidence": 0.95, "top3": [...] }

Output (not a dog):
    { "success": false, "error": "NOT_A_DOG", "message": "..." }
"""

import sys
import os
import json
import tempfile
import subprocess
import argparse


# ─── CHILD WORKER MODE ────────────────────────────────────────────────────────
# When this script is called with --_worker, it runs the actual TF inference.
# stdout is redirected to /dev/null by the parent before spawning, so nothing
# can leak. Results are written to a temp file passed via --_out.

def _worker_main(img_path: str, out_path: str, debug: bool):
    """Runs inside the child process. stdout is /dev/null."""

    # Silence TF at every possible level
    os.environ.update({
        'TF_CPP_MIN_LOG_LEVEL':      '3',
        'TF_ENABLE_ONEDNN_OPTS':     '0',
        'CUDA_VISIBLE_DEVICES':      '-1',
        'TF_FORCE_GPU_ALLOW_GROWTH': 'true',
        'PYTHONWARNINGS':            'ignore',
        'TF_SILENCE_DEPRECATION':    '1',
        'KERAS_BACKEND':             'tensorflow',
    })

    import warnings
    warnings.filterwarnings('ignore')

    import logging
    logging.disable(logging.CRITICAL)

    import numpy as np
    import tensorflow as tf
    tf.get_logger().setLevel('ERROR')
    tf.autograph.set_verbosity(0)

    from tensorflow.keras.applications import MobileNetV2
    from tensorflow.keras.applications.mobilenet_v2 import (
        preprocess_input,
        decode_predictions,
    )
    from tensorflow.keras.preprocessing import image as keras_image

    DOG_CONFIDENCE_THRESHOLD = 0.15

    DOG_BREED_SYNSETS = {
        "n02085620","n02085782","n02085936","n02086079","n02086240",
        "n02086646","n02086910","n02087046","n02087394","n02088094",
        "n02088238","n02088364","n02088466","n02088632","n02089078",
        "n02089867","n02089973","n02090379","n02090622","n02090721",
        "n02091032","n02091134","n02091244","n02091467","n02091635",
        "n02091831","n02092002","n02092339","n02093256","n02093428",
        "n02093647","n02093754","n02093859","n02093991","n02094114",
        "n02094258","n02094433","n02095314","n02095570","n02095889",
        "n02096051","n02096177","n02096294","n02096437","n02096585",
        "n02097047","n02097130","n02097209","n02097298","n02097474",
        "n02097658","n02098105","n02098286","n02098413","n02099267",
        "n02099429","n02099601","n02099712","n02099849","n02100236",
        "n02100583","n02100735","n02100877","n02101006","n02101388",
        "n02101556","n02102040","n02102177","n02102318","n02102480",
        "n02102973","n02104029","n02104365","n02105056","n02105162",
        "n02105251","n02105412","n02105505","n02105641","n02105855",
        "n02106030","n02106166","n02106382","n02106550","n02106662",
        "n02107142","n02107312","n02107574","n02107683","n02107908",
        "n02108000","n02108089","n02108422","n02108551","n02108915",
        "n02109047","n02109525","n02109961","n02110063","n02110185",
        "n02110341","n02110627","n02110806","n02110958","n02111129",
        "n02111277","n02111500","n02111889","n02112018","n02112137",
        "n02112350","n02112706","n02113023","n02113186","n02113624",
        "n02113712","n02113799","n02113978","n02114367","n02114548",
        "n02114712","n02114855","n02115641","n02115913","n02116738",
    }

    try:
        model     = MobileNetV2(weights="imagenet")
        img       = keras_image.load_img(img_path, target_size=(224, 224))
        img_array = keras_image.img_to_array(img)
        img_array = np.expand_dims(img_array, axis=0)
        img_array = preprocess_input(img_array)
        preds     = model.predict(img_array, verbose=0)
        all_preds = decode_predictions(preds, top=1000)[0]
    except Exception as e:
        result = {"success": False, "error": "INFERENCE_ERROR", "message": str(e)}
        with open(out_path, "w") as f:
            json.dump(result, f)
        return

    dog_preds = [
        (synset, label, float(score))
        for synset, label, score in all_preds
        if synset in DOG_BREED_SYNSETS
    ]
    total_dog_score = sum(s for _, _, s in dog_preds)

    # --- Stricter Dog Validation ---
    top_global_synset, top_global_label, top_global_score = all_preds[0]
    is_top_global_dog = top_global_synset in DOG_BREED_SYNSETS

    # If the top global prediction isn't a dog, we need much higher confidence to "override" it.
    # Otherwise, even a 5% "dog" hit might be normalized to 100% Chow.
    actual_threshold = DOG_CONFIDENCE_THRESHOLD
    if not is_top_global_dog:
        # If top hit is something else (e.g. Lion), require at least 40% dog sum to consider it.
        actual_threshold = max(0.40, DOG_CONFIDENCE_THRESHOLD)

    if debug:
        print(f"[DEBUG] Top Global: {top_global_label} ({top_global_score*100:.2f}%)", file=sys.stderr)
        print(f"[DEBUG] Is Top Dog: {is_top_global_dog}", file=sys.stderr)
        print(f"[DEBUG] Required Threshold: {actual_threshold*100:.2f}%", file=sys.stderr)

    if not dog_preds or total_dog_score < actual_threshold:
        result = {
            "success": True,
            "is_dog":  False,
            "error":   "NOT_A_DOG",
            "message": "The picture is not matching.",
            "detail":  (
                f"No dog detected. Top prediction was '{top_global_label}' ({top_global_score*100:.1f}%). "
                f"Dog confidence: {total_dog_score*100:.1f}%, required for this image: {actual_threshold*100:.1f}%."
            ),
        }
    else:
        dog_preds_norm = sorted(
            [(syn, lbl, sc / total_dog_score) for syn, lbl, sc in dog_preds],
            key=lambda x: x[2], reverse=True,
        )
        top3 = [
            {
                "breed":          lbl.replace("_", " ").title(),
                "confidence":     round(conf, 4),
                "confidence_pct": f"{conf*100:.1f}%",
            }
            for _, lbl, conf in dog_preds_norm[:3]
        ]
        _, best_lbl, best_conf = dog_preds_norm[0]
        result = {
            "success":        True,
            "is_dog":         True,
            "breed":          best_lbl.replace("_", " ").title(),
            "confidence":     round(best_conf, 4),
            "confidence_pct": f"{best_conf*100:.1f}%",
            "top3":           top3,
        }

    with open(out_path, "w") as f:
        json.dump(result, f)


# ─── PARENT LAUNCHER MODE ─────────────────────────────────────────────────────
# This is what your app calls. It spawns a child worker with stdout → /dev/null,
# reads the result JSON file, then prints clean JSON to stdout.

def _parent_main(img_path: str, debug: bool):
    # Write result to a temp file — no IPC over stdout needed
    tmp      = tempfile.NamedTemporaryFile(suffix=".json", delete=False)
    tmp.close()
    out_path = tmp.name

    try:
        cmd = [
            sys.executable, __file__,
            img_path,
            "--_worker",
            "--_out", out_path,
        ]
        if debug:
            cmd.append("--debug")

        # Redirect child's stdout to /dev/null at the OS level.
        # This is the key fix — no TF output can ever reach the parent's stdout.
        with open(os.devnull, "w") as devnull:
            proc = subprocess.run(
                cmd,
                stdout=devnull,    # child stdout → /dev/null
                stderr=sys.stderr, # debug logs visible on stderr
                timeout=120,
            )

        # Read result from temp file
        if not os.path.exists(out_path) or os.path.getsize(out_path) == 0:
            raise RuntimeError(
                f"Worker process exited with code {proc.returncode} "
                "and produced no output. Check dependencies (tensorflow, numpy)."
            )

        with open(out_path) as f:
            result = json.load(f)

    except subprocess.TimeoutExpired:
        result = {
            "success": False,
            "error":   "TIMEOUT",
            "message": (
                "Breed detection timed out (>120s). "
                "Check your internet connection — model weights may still be downloading."
            ),
        }
    except Exception as e:
        result = {
            "success": False,
            "error":   "LAUNCHER_ERROR",
            "message": str(e),
        }
    finally:
        try:
            os.unlink(out_path)
        except Exception:
            pass

    # ── THIS IS THE ONLY LINE THAT WRITES TO STDOUT ──────────────────
    print(json.dumps(result))
    return result


# ─── ENTRY POINT ──────────────────────────────────────────────────────────────

if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="PawDetect — CNN Dog Breed Detector",
        add_help=True,
    )
    parser.add_argument("image_path",  nargs="?", default=None,
                        help="Path to the dog image file")
    parser.add_argument("--debug",     action="store_true",
                        help="Show raw top-5 predictions on stderr")
    # Internal flags used by worker subprocess — hidden from --help
    parser.add_argument("--_worker",   action="store_true", help=argparse.SUPPRESS)
    parser.add_argument("--_out",      default=None,        help=argparse.SUPPRESS)

    args = parser.parse_args()

    # ── Validate image path ───────────────────────────────────────────
    if not args.image_path:
        print(json.dumps({
            "success": False,
            "error":   "MISSING_ARGUMENT",
            "message": "Usage: python dog_breed_detector.py <image_path> [--debug]",
        }))
        sys.exit(1)

    if not os.path.exists(args.image_path):
        print(json.dumps({
            "success": False,
            "error":   "FILE_NOT_FOUND",
            "message": f"File not found: {args.image_path}",
        }))
        sys.exit(1)

    # ── Route to worker or parent ─────────────────────────────────────
    if args._worker:
        # Running as child subprocess — stdout is already /dev/null
        _worker_main(args.image_path, args._out, args.debug)
        sys.exit(0)
    else:
        # Running as parent — spawns the worker, then prints clean JSON
        result = _parent_main(args.image_path, args.debug)
        sys.exit(0 if result.get("success") else 1)
