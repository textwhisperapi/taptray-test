const secretSalt = "s3rv3r-sh@red"; // keep private and consistent

async function getKeyForList(listToken) {
  const enc = new TextEncoder();

  const keyMaterial = await window.crypto.subtle.importKey(
    "raw", enc.encode(listToken),
    { name: "PBKDF2" },
    false,
    ["deriveKey"]
  );

  return crypto.subtle.deriveKey(
    {
      name: "PBKDF2",
      salt: enc.encode(secretSalt),
      iterations: 100000,
      hash: "SHA-256"
    },
    keyMaterial,
    { name: "AES-GCM", length: 256 },
    false,
    ["encrypt", "decrypt"]
  );
}

async function encryptMessage(plaintext, key) {
  const enc = new TextEncoder();
  const iv = window.crypto.getRandomValues(new Uint8Array(12));
  const ciphertext = await window.crypto.subtle.encrypt(
    { name: "AES-GCM", iv }, key, enc.encode(plaintext)
  );
  return "ENC:" + btoa(JSON.stringify({
    iv: Array.from(iv),
    data: Array.from(new Uint8Array(ciphertext))
  }));
}

async function decryptMessage(ciphertextBase64, key) {
  const input = ciphertextBase64.startsWith("ENC:")
    ? ciphertextBase64.slice(4)
    : ciphertextBase64;

  try {
    const { iv, data } = JSON.parse(atob(input));
    const ivBuf = new Uint8Array(iv);
    const dataBuf = new Uint8Array(data);
    const decrypted = await window.crypto.subtle.decrypt(
      { name: "AES-GCM", iv: ivBuf }, key, dataBuf
    );
    return new TextDecoder().decode(decrypted);
  } catch (err) {
    console.warn("🔓 Not decryptable, returning as plaintext");
    return ciphertextBase64;
  }
}

function isProbablyEncrypted(str) {
  return str.startsWith("ENC:");
}
