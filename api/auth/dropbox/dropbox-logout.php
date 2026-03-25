<?php
session_start();
unset($_SESSION['DROPBOX_ACCESS_TOKEN']);
unset($_SESSION['DROPBOX_REFRESH_TOKEN']);
http_response_code(204);
