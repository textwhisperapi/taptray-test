logStep("JSExtractURLs.js executed");

function extractUrls(text) {
    const urlPattern = /(https?:\/\/[^\s]+)/g;
    const matches = text.match(urlPattern) || [];

    let soundsliceUrl = "";
    let pdfUrl = "";

    for (let url of matches) {
        url = url.replace(/\/+$/, ""); // Remove trailing slashes

        // Detect Soundslice
        if (!soundsliceUrl && url.includes("soundslice.com/slices/")) {
            soundsliceUrl = url;
            continue;
        }

        // Detect Google Drive → check for patch
        if (!pdfUrl && url.includes("drive.google.com/file/d/")) {
            const match = url.match(/\/d\/([a-zA-Z0-9_-]+)/);
            const fileId = match ? match[1] : null;

            if (fileId) {
                const patched = localStorage.getItem(`patched-pdf-${fileId}`);
                pdfUrl = patched || url;

                if (patched) {
                    console.log("📦 Using patched PDF:", patched);
                }
                continue;
            }
        }

        // Use the first non-Soundslice link as fallback PDF URL
        if (!pdfUrl) {
            pdfUrl = url;
        }

        if (soundsliceUrl && pdfUrl) break;
    }

    window.soundsliceUrl = soundsliceUrl;
    window.otherUrl = pdfUrl;

    console.log("🎶 Extracted Soundslice URL:", window.soundsliceUrl);
    console.log("📄 Extracted PDF URL:", window.otherUrl);
}


function extractOnLoad() {
    const textarea = document.getElementById("myTextarea");

    if (!textarea) {
        console.error("❌ Textarea not found!");
        return;
    }

    let textContent = textarea.value.trim();

    if (!textContent) {
        //console.warn("⚠️ No text content found. Retrying...");
        //setTimeout(extractOnLoad, 500);
        console.warn("⚠️ No text content found.");
        return;
    }

    console.log("📄 Extracting URLs from updated text...");
    extractUrls(textContent);
}

// ✅ Ensure script runs after DOM loads
document.addEventListener("DOMContentLoaded", function () {
    console.log("🚀 JSExtractURLs Loaded!");
});
