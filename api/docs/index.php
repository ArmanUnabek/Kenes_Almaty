<?php
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>OS API Docs</title>
  <link rel="icon" type="image/png" href="/assets/vendor/swagger/favicon-32x32.png" />
  <link rel="stylesheet" href="/assets/vendor/swagger/swagger-ui.css?v=39" />
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="/assets/vendor/swagger/swagger-ui-bundle.js?v=39"></script>
  <script nonce="<?= $nonce ?>">
    window.ui = SwaggerUIBundle({
      url: './openapi.json',
      dom_id: '#swagger-ui'
    });
  </script>
</body>
</html>
