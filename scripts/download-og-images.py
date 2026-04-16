#!/usr/bin/env python3
"""Generate and download OG images from opengraph.xyz CDN to site/images/og/"""
import urllib.parse, subprocess, os

BASE = "https://ogcdn.net/0f1b6ed8-69a3-4fda-a594-ff415029ecd8/v2"
LOGO = "https://directsponsor.net/images/branding/arrow%20logo.png"
BRAND = "🎯 DirectSponsor"
OUT_DIR = os.path.join(os.path.dirname(__file__), "../site/images/og")

def url(brand, category, title, desc, cta, img, highlight, logo):
    e = lambda s: urllib.parse.quote(s, safe='')
    parts = [e(brand), e(category), e(title), e(desc), e(cta), e(img), e(highlight), e(logo)]
    return BASE + "/" + "/".join(parts) + "/og.png"

pages = [
    ("og-home", BRAND, "PEER-TO-PEER IMPACT",
        "Revolutionary Peer-to-Peer Charity",
        "Eliminate charity middlemen. Send money directly to verified recipients with complete transparency.",
        "Browse Fundraisers", "_", "To Your Recipient", LOGO),
    ("og-fundraisers", BRAND, "PEER-TO-PEER IMPACT",
        "Browse Active Fundraisers",
        "Support real people making a difference. Bitcoin Lightning payments go directly to recipients.",
        "Donate Now", "_", "Direct to Recipient", LOGO),
    ("og-about", BRAND, "ABOUT US",
        "What is DirectSponsor?",
        "A peer-to-peer platform connecting donors directly with recipients. No middlemen, no fees, full transparency.",
        "Learn More", "_", "No Middlemen", LOGO),
    ("og-posts", BRAND, "COMMUNITY",
        "Stories from the Field",
        "Updates, news and stories directly from recipients and donors around the world.",
        "Read Stories", "_", "From Recipients", LOGO),
    ("og-donate", BRAND, "GETTING STARTED",
        "How to Donate with Bitcoin Lightning",
        "New to Bitcoin? No problem. Get started in minutes with a free Lightning wallet and make your first donation.",
        "Get Started Free", "_", "In Minutes", LOGO),
]

os.makedirs(OUT_DIR, exist_ok=True)

for (filename, brand, category, title, desc, cta, img, highlight, logo) in pages:
    u = url(brand, category, title, desc, cta, img, highlight, logo)
    out = os.path.join(OUT_DIR, filename + ".png")
    print(f"Downloading {filename}.png ...")
    print(f"  URL: {u}")
    result = subprocess.run(["curl", "-sL", "-o", out, u])
    if result.returncode == 0:
        size = os.path.getsize(out)
        print(f"  ✓ Saved ({size} bytes)")
    else:
        print(f"  ✗ Failed")
