
// function textTrimmerXXX() {
//     var txt = "";
//     var tcols = 24;
//     var prevle = le;
//     var le = document.getElementById("b").value;

//     // Set column width based on textarea size
//     tcols = parseInt($("#myTextarea").width() / 7.13 - 2);

//     // If `le` is 0, copy text from myTextarea2 to myTextarea
//     if (le <= 0) {
//         document.getElementById("myTextarea").value = document.getElementById("myTextarea2").value;
//         return;
//     }

//     var a = document.getElementById("myTextarea2").value;
//     var txt1 = "";
//     var hash = 0;
//     var comma = " ";
//     var eol = "";
//     var wordi = "";

//     // Normalize line breaks and split text into words
//     var b = a.replace(/(\r\n|\n|\r)/gm, " \r\n ");
//     // var arr = b.split(" ");
//     //split to words on spaces and dots
//     var arr = b.split(/[ .]+/);


//     // ðŸ”¹ Emoji detection regex (covers most Unicode emoji ranges)
//     var emojiRegex = /[\p{Extended_Pictographic}]/gu;

// let firstLine = true;


//     for (var i = 0; i < arr.length; i++) {
//         comma = " ";
//         eol = "";
//         wordi = arr[i];

//         // Preserve lines starting with #
//         // if (arr[i].indexOf("#") !== -1) {
//         //     hash = 1;
//         // }
            
//         // Preserve if:
//         // 1. Line contains '#'
//         // 2. Word starts with 3+ initial caps
//         // 3. We're still on the very first line
//         if (
//           arr[i].indexOf("#") !== -1 ||
//           /^[^A-Za-z]*[A-Z]{3}/.test(arr[i]) ||
//           firstLine
//         ) {
//           hash = 1;
//         }

// firstLine = false; 
	        
//         // Handle new line cases
//         if (arr[i].lastIndexOf("\n") > 0) {
//             eol = "\r\n";
//             wordi = " ";
//             txt1 = "";
//             hash = 0; // Reset hash for new lines
//         }

//         // If hash is set, continue to next word without modifying
//         if (hash > 0) {
//             txt += arr[i] + " ";
//             continue;
//         }

//         // ðŸ”¹ Extract emojis separately & process text normally
//         let emojis = wordi.match(emojiRegex) || []; // Save emojis
//         let cleanWord = wordi.replace(emojiRegex, ""); // Remove emojis from text

//         // Detect and handle punctuation (comma or period)
//         var n = cleanWord.lastIndexOf(".");
//         if (n < 0) {
//             n = cleanWord.lastIndexOf(",");
//         }

//         if (n > 0) {
//             comma = cleanWord.substr(n, 1) + " ";
//         } else {
//             comma = " ";
//         }

//         txt1 += cleanWord + " "; // Process text without emojis
//         var l = (txt1 + arr[i + 1]).length;

//         // Wrap text when it exceeds `tcols`
//         if (l > tcols) {
//             eol = "\r\n";
//             txt1 = "";
//         }

//         // ðŸ”¹ Restore emojis after processing
//         txt += cleanWord.valueOf().substr(0, le) + emojis.join("") + comma + eol;
//     }

//     document.getElementById("myTextarea").value = txt;
// }


// function textTrimmerMMM() {

    
//   let txt = "";
//   let tcols = 24;

//   const slider = document.getElementById("b");
//   if (!slider) return;

//   const le = parseInt(slider.value) || 0;


//   const ta1 = document.getElementById("myTextarea");   // editable div
//   const ta2 = document.getElementById("myTextarea2");  // readonly div
//   if (!ta1 || !ta2) return;
  
//   //If slider = 0 → just mirror T2 (keep highlights/comments)
//   if (le === 0) {
//     ta1.innerHTML = ta2.innerHTML;
//     return;
//   }  

//   // Set column width based on visible mirror width
//   const basePx = ta2.clientWidth || ta1.clientWidth || 600;
//   tcols = Math.max(parseInt(basePx / 7.13 - 2), 20);

//   // Source text: plain text from the mirror div
// //   const rawText = ta2.innerText || "";


// //   const rawText =  ta2.innerHTML || "";

// // Source text: plain text from the mirror div
// // const rawText = ta2.textContent || "";

// //  const rawText = window._T2_RAWTEXT || "";
// //   if (!rawText) return;
// //   let a = rawText;

// const rawHtml = window._T2_RAWHTML || "";
// if (!rawHtml) return;

// // Convert <br> to real line breaks (same as before)
// let a = rawHtml
//   .replace(/<br\s*\/?>/gi, "\n")
//   .replace(/&nbsp;/g, " ");
  
//     //Remove ALL remaining HTML tags (h3, a, blockquote, b, i, etc.)
//     // IMPORTANT: preserve <b> and <strong> by temporarily marking them
//     a = a
//       .replace(/<strong>/gi, "[[STRONG]]")
//       .replace(/<\/strong>/gi, "[[/STRONG]]")
//       .replace(/<b>/gi, "[[B]]")
//       .replace(/<\/b>/gi, "[[/B]]");
    
//     // Remove all OTHER tags
//     a = a.replace(/<\/?[^>]+>/g, "");
    
//     // Restore bold markers
//     a = a
//       .replace(/\[\[STRONG\]\]/g, "<strong>")
//       .replace(/\[\[\/STRONG\]\]/g, "</strong>")
//       .replace(/\[\[B\]\]/g, "<b>")
//       .replace(/\[\[\/B\]\]/g, "</b>");
  



//   let txt1 = "";
//   let hash = 0;
//   let comma = " ";
//   let eol = "";
//   let wordi = "";

//   // Normalize line breaks and split text into words
//   const b = a.replace(/(\r\n|\n|\r)/gm, " \r\n ");
// //   const arr = b.split(/[ .]+/);
//   const arr = b.split(/(?<=\>)|[ .]+/);


//   const emojiRegex = /[\p{Extended_Pictographic}]/gu;
//   let firstLine = true;

//     for (let i = 0; i < arr.length; i++) {
//       comma = " ";
//       eol = "";
//       const token = arr[i];   // keep original token for formatting info
//       wordi = token;
    
//       // Preserve lines starting with #
//       if (
//         token.indexOf("#") !== -1 ||
//         // /^[^A-Za-z]*[A-Z]{3}/.test(token) ||
//         /^[A-Z]{3,}$/.test(token) ||
//         firstLine
//       ) {
//         hash = 1;
//       }
    
//       firstLine = false;
    
//       // Handle new line cases
//       if (token.lastIndexOf("\n") > 0) {
//         eol = "\r\n";
//         wordi = " ";
//         txt1 = "";
//         hash = 0;
//       }
    
//       if (hash > 0) {
//         txt += token + " ";
//         continue;
//       }
    
//       // 👉 Was this word originally bold?
//       const wasBold = /<\/?(b|strong)[^>]*>/i.test(token);
    
//       // Emoji handling (on the plain text part)
//       const emojis = wordi.match(emojiRegex) || [];
//       const cleanWord = wordi.replace(emojiRegex, "");
    
//       // Detect punctuation
//       let n = cleanWord.lastIndexOf(".");
//       if (n < 0) n = cleanWord.lastIndexOf(",");
//       comma = n > 0 ? cleanWord.substr(n, 1) + " " : " ";
    
//       txt1 += cleanWord + " ";
//       const l = (txt1 + (arr[i + 1] || "")).length;
    
//       // Wrap text
//       if (l > tcols) {
//         eol = "\r\n";
//         txt1 = "";
//       }
    
//       // 🔪 Trim visible part
//       let trimmed = cleanWord.substr(0, le) + emojis.join("");
    
//       // 💪 Re-apply bold if this token was bold
//       if (wasBold && trimmed) {
//         trimmed = `<b>${trimmed}</b>`;
//       }
    
//       txt += trimmed + comma + eol;
//     }


//   //Render trimmed text with <br>
// //   ta1.innerHTML = escapeHtml(txt).replace(/\r?\n/g, "<br>");
//   ta1.innerHTML = txt.replace(/\r?\n/g, "<br>");
// }


function textTrimmer() {
  let txt = "";

  const slider = document.getElementById("b");
  if (!slider) return;

  const le = parseInt(slider.value, 10) || 0;

  const ta1 = document.getElementById("myTextarea");   // editable
  const ta2 = document.getElementById("myTextarea2");  // readonly mirror
  if (!ta1 || !ta2) return;

  // If slider = 0 → just mirror T2 (keep all HTML formatting)
  if (le === 0) {
    ta1.innerHTML = ta2.innerHTML;
    return;
  }

  // Column width based on visible mirror width
  const basePx = ta2.clientWidth || ta1.clientWidth || 600;
  const tcols = Math.max(Math.floor(basePx / 7.13 - 2), 20);

  // ---- Source HTML (already sanitized: <br>, <b>, <i>, <u>) ----
  const rawHtml = window._T2_RAWHTML || ta2.innerHTML || "";
  if (!rawHtml) return;

  // Split into HTML "lines" by <br>
  const htmlLines = rawHtml.split(/<br\s*\/?>/i);

  // Helper: strip tags to get plain text
  const stripTags = s =>
    (s || "")
      .replace(/<\/?[^>]+>/g, "")
      .replace(/&nbsp;/g, " ")
      .trim();

  // Decide whether to preserve a line as-is (headings, speakers, first line)
  function shouldPreserveLine(plain, isFirstNonEmpty) {
    if (!plain) return false;
    if (isFirstNonEmpty) return true;
    if (plain.includes("#")) return true;

    // ALL CAPS heading, possibly with spaces/punctuation
    if (/^[A-Z0-9 .,'’\-:;]+$/.test(plain) && plain === plain.toUpperCase()) {
      return true;
    }

    // Single-word speaker name in ALL CAPS: SAMPSON, GREGORY, etc.
    if (/^[A-Z][A-Z'.-]+$/.test(plain)) {
      return true;
    }

    return false;
  }

  // Emoji regex
  const emojiRegex = /[\p{Extended_Pictographic}]/gu;

  // Trim a single plain-text line with word-wrap
  function trimPlainLine(plain) {
    if (!plain) return "";

    const words = plain.split(/\s+/);
    let currentLen = 0;
    let out = "";
    let firstToken = true;

    for (let w of words) {
      if (!w) continue;

      // Separate trailing punctuation . or ,
      let punct = "";
      const mP = w.match(/[.,]$/);
      if (mP) {
        punct = mP[0];
        w = w.slice(0, -1);
      }

      // Extract emojis
      const emojis = w.match(emojiRegex) || [];
      let bare = w.replace(emojiRegex, "");

      // Trim the bare word
      const trimmedBare = bare.slice(0, le);
      const token = trimmedBare + emojis.join("") + punct;

      if (!token) continue;

      const tokenLen = token.length + (firstToken ? 0 : 1); // +1 for space if not first

      // Wrap if needed
      if (currentLen + tokenLen > tcols && !firstToken) {
        out += "\n";
        currentLen = token.length;
        out += token;
      } else {
        if (!firstToken) {
          out += " ";
        }
        out += token;
        currentLen += tokenLen;
      }

      firstToken = false;
    }

    return out;
  }

  let firstNonEmptySeen = false;

  htmlLines.forEach((htmlLine, idx) => {
    const plain = stripTags(htmlLine);

    // Preserve exact empty lines
    if (!plain) {
      // But avoid extra blank line at the very start
      if (idx > 0) txt += "\n";
      return;
    }

    const preserve = shouldPreserveLine(plain, !firstNonEmptySeen);
    if (!firstNonEmptySeen && plain) {
      firstNonEmptySeen = true;
    }

    if (preserve) {
      // Keep original HTML of the line, but normalize spaces
      const preserved = htmlLine.trim();
      if (preserved) {
        if (txt) txt += "\n";
        txt += preserved.replace(/\s+/g, " ");
      }
    } else {
      const trimmed = trimPlainLine(plain);
      if (trimmed) {
        if (txt) txt += "\n";
        txt += trimmed;
      }
    }
  });

  // Render: convert internal newlines to <br>
  ta1.innerHTML = txt.replace(/\n/g, "<br>");
}

//Visually line baset trimmer experiment

