<?php ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Folder Icon Test</title>

<style>
  body {
    background:#ffffff;
    color:#222;
    font-family:system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    padding:24px;
  }

  .row {
    margin-bottom:14px;
    font-size:16px;
  }

  /* Windows-like folder */
  .folder {
    display:inline-block;
    width:18px;
    height:13px;
    margin-right:8px;
    vertical-align:middle;
    border-radius:2px;
    position:relative;
  }

  .folder::before {
    content:"";
    position:absolute;
    top:-3px;
    left:2px;
    width:8px;
    height:4px;
    border-radius:2px 2px 0 0;
    background:inherit;
  }

  /* provider colors */
  .google   { background:#5F6368; }
  .dropbox  { background:#A7C7FF; }
  .onedrive { background:#F2C94C; }
  .tw       { background:#6ECFF6; }
  .icloud   { background:#D1D5DB; }
</style>
</head>

<body>

<div class="row"><span class="folder google"></span>Google Drive</div>
<div class="row"><span class="folder dropbox"></span>Dropbox</div>
<div class="row"><span class="folder onedrive"></span>OneDrive</div>
<div class="row"><span class="folder tw"></span>TextWhisper</div>
<div class="row"><span class="folder icloud"></span>iCloud Drive</div>

</body>
</html>
