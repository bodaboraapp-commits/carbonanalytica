from PIL import Image
import os
art = r"C:\xampp\htdocs\www\CarbonAnalytica\AIPDDGen\storage\artifacts"
d = Image.open(os.path.join(art, "drop-desktop.png"))
d.crop((0, 0, d.width, 480)).save(os.path.join(art, "drop-desktop-crop.png"))
m = Image.open(os.path.join(art, "drop-mobile.png"))
m.crop((0, 0, m.width, 700)).save(os.path.join(art, "drop-mobile-crop.png"))
print(d.size, m.size)
