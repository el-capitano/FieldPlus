import os
from PIL import Image

def build_google_play_assets():
    brain_dir = r"C:\Users\Majed\.gemini\antigravity\brain\8252fc13-6ebc-471e-86af-8152d14685d1"
    output_dir = r"c:\Users\Majed\Desktop\development\Native Android\FieldPlus\google_play_assets"
    os.makedirs(output_dir, exist_ok=True)
    
    # 1. APP ICON: Exactly 512 x 512 px, 32-bit PNG, no alpha, max 1024KB
    icon_src = os.path.join(brain_dir, "fieldplus_icon_1791584293635.jpg")
    if os.path.exists(icon_src):
        with Image.open(icon_src) as img:
            icon_512 = img.convert("RGB").resize((512, 512), Image.Resampling.LANCZOS)
            icon_path = os.path.join(output_dir, "01_app_icon_512x512.png")
            icon_512.save(icon_path, format="PNG", optimize=True)
            print("Generated 512x512 App Icon:", icon_path, os.path.getsize(icon_path), "bytes")
            
    # 2. FEATURE GRAPHIC: Exactly 1024 x 500 px, JPEG or 24-bit PNG (no alpha), max 15MB
    # We use the official promo hero banner
    feature_src = os.path.join(brain_dir, "fieldplus_promo_banner_1791663343786.jpg")
    if os.path.exists(feature_src):
        with Image.open(feature_src) as img:
            feature_1024 = img.convert("RGB").resize((1024, 500), Image.Resampling.LANCZOS)
            feature_path = os.path.join(output_dir, "02_feature_graphic_1024x500.png")
            feature_1024.save(feature_path, format="PNG", optimize=True)
            print("Generated 1024x500 Feature Graphic:", feature_path, os.path.getsize(feature_path), "bytes")
            
    # 3. PHONE SCREENSHOTS: 1080 x 1920 (16:9 aspect ratio, between 320px and 3840px, max 8MB each)
    screenshots = [
        ("03_screenshot_1_forms_catalog.png", "gp_screen_forms_list_1791666557898.jpg"),
        ("04_screenshot_2_live_form_inspection.png", "gp_screen_form_fill_1791666844063.jpg"),
        ("05_screenshot_3_evidence_and_outbox.png", "gp_screen_evidence_gallery_1791667147168.jpg"),
        ("06_screenshot_4_gps_satellite_map.png", "gp_screen_live_map_tracking_1791667523680.jpg")
    ]
    
    for out_name, in_name in screenshots:
        src_file = os.path.join(brain_dir, in_name)
        if os.path.exists(src_file):
            with Image.open(src_file) as img:
                # Resize cleanly to standard 1080x1920
                shot_1080 = img.convert("RGB").resize((1080, 1920), Image.Resampling.LANCZOS)
                out_path = os.path.join(output_dir, out_name)
                shot_1080.save(out_path, format="PNG", optimize=True)
                print("Generated Store Screenshot:", out_path, os.path.getsize(out_path), "bytes")

if __name__ == "__main__":
    build_google_play_assets()
