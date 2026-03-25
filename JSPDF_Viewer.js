const url = "/Documents/Ast_TTBB.pdf";  // Path to your PDF file

// Initialize PDF.js and load the PDF document
pdfjsLib.getDocument(url).promise.then(function (pdfDoc_) {
    pdfDoc = pdfDoc_;
    const totalPages = pdfDoc.numPages;
    console.log("Total pages: " + totalPages);  // Show the total number of pages

    // Render all pages
    for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
        renderPage(pageNum);
    }
});

// Function to render a specific page
function renderPage(pageNum) {
    pdfDoc.getPage(pageNum).then(function (page) {
        // Get the viewport size based on the container width
        const containerWidth = document.getElementById("pdfContainer").offsetWidth;
        const scale = containerWidth / page.getViewport({ scale: 1 }).width;  // Dynamically adjust scale

        const viewport = page.getViewport({ scale: scale });

        // Create a canvas for each page and add it to the container
        const canvas = document.createElement("canvas");
        canvas.classList.add("pdf-canvas");  // Optional: to add custom styles for each canvas
        const context = canvas.getContext("2d");

        canvas.height = viewport.height;
        canvas.width = viewport.width;

        // Append the canvas to the PDF container
        document.getElementById("pdfContainer").appendChild(canvas);

        // Render the page onto the canvas
        page.render({
            canvasContext: context,
            viewport: viewport
        });
    });
}
