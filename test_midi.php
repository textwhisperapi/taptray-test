<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>MIDI Encoding Test Tool</title>
<style>
body { font-family: Arial; padding:20px; line-height:1.5; }
input[type=text] { width: 600px; padding: 6px; }
button { padding: 8px 14px; margin-top: 10px; }
#out { background:#f4f4f4; padding:10px; margin-top:20px; white-space:pre-wrap; }
.ok { color:green; font-weight:bold; }
.bad { color:red; font-weight:bold; }
</style>
</head>
<body>

<h2>MIDI URL Encoding Test</h2>

<p>Paste a MIDI URL from <code>audio.textwhisper.com</code>:</p>

<input type="text" id="url" placeholder="https://audio.textwhisper.com/.../file.mid">
<br>
<button onclick="runEncodingTest()">Run Encoding Test</button>

<div id="out"></div>

<script>
function extractKey(url) {
    const idx = url.indexOf("audio.textwhisper.com/");
    if (idx === -1) return null;
    return url.substring(idx + "audio.textwhisper.com/".length);
}

async function runEncodingTest() {
    const raw = document.getElementById("url").value.trim();
    const out = document.getElementById("out");
    out.innerHTML = "";

    if (!raw) {
        out.innerHTML = "Enter a URL first.";
        return;
    }

    // ------------ Build URLs ------------
    const encodedUrl = encodeURI(raw);      // percent-encoded version
    const decodedUrl = decodeURI(raw);      // literal Unicode version

    out.innerHTML += "Original input:\n" + raw + "\n\n";
    out.innerHTML += "Encoded URL:\n" + encodedUrl + "\n\n";
    out.innerHTML += "Decoded URL (unicode path):\n" + decodedUrl + "\n\n";

    out.innerHTML += "--------------------------------------------------\n";
    out.innerHTML += "TEST A: Fetch encoded URL (expected to work)\n";
    await testFetch(encodedUrl);

    out.innerHTML += "\n--------------------------------------------------\n";
    out.innerHTML += "TEST B: Fetch decoded URL (expected to fail if theory is correct)\n";
    await testFetch(decodedUrl);

    out.innerHTML += "\n--------------------------------------------------\n";
    out.innerHTML += "curl command you can test manually:\n";
    out.innerHTML += "curl -I \"" + encodedUrl + "\"\n";
}

async function testFetch(url) {
    const out = document.getElementById("out");

    out.innerHTML += "Fetching: " + url + "\n";

    let res;
    try {
        res = await fetch(url);
    } catch (e) {
        out.innerHTML += "Fetch ERROR: " + e + "\n\n";
        return;
    }

    const ct = res.headers.get("Content-Type");
    const ao = res.headers.get("Access-Control-Allow-Origin");

    out.innerHTML += "Status: " + res.status + "\n";
    out.innerHTML += "Content-Type: " + ct + "\n";
    out.innerHTML += "Access-Control-Allow-Origin: " + ao + "\n";

    let buf = await res.arrayBuffer();
    out.innerHTML += "ArrayBuffer size: " + buf.byteLength + "\n";

    let header = "";
    if (buf.byteLength >= 4) {
        const b = new Uint8Array(buf.slice(0,4));
        header = String.fromCharCode(...b);
    }

    out.innerHTML += "First 4 bytes: " + header + "\n";

    if (header === "MThd") {
        out.innerHTML += "<span class='ok'>MIDI OK</span>\n";
    } else {
        out.innerHTML += "<span class='bad'>BROKEN (no MThd)</span>\n";
    }

    out.innerHTML += "\n";
}
</script>

</body>
</html>
