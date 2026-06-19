"""
PawDetect / Dog Breed Classifier — FastAPI Server Wrapper
=========================================================
Exposes a REST API endpoint for dog breed classification.
Loads the TensorFlow/MobileNetV2 model once at startup to ensure sub-second inference speeds.

Deploy this file to Render, Railway, or Hugging Face Spaces.
Requirements:
    pip install fastapi uvicorn python-multipart tensorflow numpy pillow
"""

import os
import io
import uvicorn
from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
import numpy as np
from PIL import Image

# Silencing TensorFlow log pollution
os.environ.update({
    'TF_CPP_MIN_LOG_LEVEL': '3',
    'TF_ENABLE_ONEDNN_OPTS': '0',
    'CUDA_VISIBLE_DEVICES': '-1',
})

import tensorflow as tf
from tensorflow.keras.applications import MobileNetV2
from tensorflow.keras.applications.mobilenet_v2 import (
    preprocess_input,
    decode_predictions,
)

app = FastAPI(
    title="Dog Breed Classifier AI API",
    description="MobileNetV2 CNN classifier wrapped in FastAPI for cloud hosting environments.",
    version="1.0.0"
)

# Enable CORS so the API can be called from different origins
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# 120 Dog breed synsets from ImageNet WordNet Database
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

# Global model instance
model = None

@app.on_event("startup")
def load_model():
    global model
    print("[AI API] Loading MobileNetV2 model weights...")
    model = MobileNetV2(weights="imagenet")
    print("[AI API] Model loaded successfully.")

@app.get("/")
def read_root():
    return {"status": "online", "model": "MobileNetV2 (ImageNet)", "app": "Dog Breed Classifier API"}

@app.post("/predict")
async def predict_breed(file: UploadFile = File(...)):
    global model
    if model is None:
        raise HTTPException(status_code=503, detail="Model is still loading or unavailable.")
    
    # Verify file type
    if not file.content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="Uploaded file is not an image.")
        
    try:
        # Read image contents into memory
        contents = await file.read()
        img = Image.open(io.BytesIO(contents)).convert("RGB")
        
        # Resize to MobileNetV2 target resolution
        img = img.resize((224, 224))
        
        # Preprocessing image to match network inputs
        img_array = np.array(img)
        img_array = np.expand_dims(img_array, axis=0)
        img_array = preprocess_input(img_array.astype(np.float32))
        
        # Perform CNN classification
        preds = model.predict(img_array, verbose=0)
        all_preds = decode_predictions(preds, top=1000)[0]
    except Exception as e:
        return JSONResponse(status_code=500, content={
            "success": False,
            "error": "INFERENCE_ERROR",
            "message": f"Inference failure: {str(e)}"
        })

    # Separate dog predictions
    dog_preds = [
        (synset, label, float(score))
        for synset, label, score in all_preds
        if synset in DOG_BREED_SYNSETS
    ]
    total_dog_score = sum(s for _, _, s in dog_preds)

    # Stricter validation boundaries matching dog_breed_detector.py
    top_global_synset, top_global_label, top_global_score = all_preds[0]
    is_top_global_dog = top_global_synset in DOG_BREED_SYNSETS

    DOG_CONFIDENCE_THRESHOLD = 0.15
    actual_threshold = DOG_CONFIDENCE_THRESHOLD
    if not is_top_global_dog:
        # Increase threshold if the top class overall is non-dog
        actual_threshold = max(0.40, DOG_CONFIDENCE_THRESHOLD)

    if not dog_preds or total_dog_score < actual_threshold:
        return {
            "success": True,
            "is_dog": False,
            "error": "NOT_A_DOG",
            "message": "The picture is not matching.",
            "detail": (
                f"No dog detected. Top prediction was '{top_global_label}' ({top_global_score*100:.1f}%). "
                f"Dog confidence: {total_dog_score*100:.1f}%, required for this image: {actual_threshold*100:.1f}%."
            )
        }
    else:
        # Normalize relative probabilities
        dog_preds_norm = sorted(
            [(syn, lbl, sc / total_dog_score) for syn, lbl, sc in dog_preds],
            key=lambda x: x[2], reverse=True,
        )
        top3 = [
            {
                "breed": lbl.replace("_", " ").title(),
                "confidence": round(conf, 4),
                "confidence_pct": f"{conf*100:.1f}%",
            }
            for _, lbl, conf in dog_preds_norm[:3]
        ]
        _, best_lbl, best_conf = dog_preds_norm[0]
        
        return {
            "success": True,
            "is_dog": True,
            "breed": best_lbl.replace("_", " ").title(),
            "confidence": round(best_conf, 4),
            "confidence_pct": f"{best_conf*100:.1f}%",
            "top3": top3,
        }

if __name__ == "__main__":
    # Get port from env (Render/Railway bind dynamic ports)
    port = int(os.environ.get("PORT", 8000))
    uvicorn.run(app, host="0.0.0.0", port=port)
