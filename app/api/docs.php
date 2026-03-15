<?php
/**
 * API Documentation Page
 *
 * Interactive documentation viewer using Swagger UI
 * to render the OpenAPI specification.
 */

$specUrl = '/api/openapi.json';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'Silo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation - <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fafafa;
        }

        /* Header */
        .docs-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .docs-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .docs-header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.1);
            border-radius: 6px;
            transition: background 0.2s;
        }

        .docs-header a:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Quick start section */
        .quick-start {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .quick-start-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .quick-start h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: #1a1a2e;
        }

        .quick-start-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .code-example {
            background: #1a1a2e;
            border-radius: 8px;
            padding: 1rem;
            color: #e8e8e8;
            font-family: 'Fira Code', monospace;
            font-size: 0.85rem;
            overflow-x: auto;
        }

        .code-example .comment {
            color: #6b7280;
        }

        .code-example .string {
            color: #10b981;
        }

        .code-example .keyword {
            color: #8b5cf6;
        }

        .example-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }

        /* Swagger UI customizations */
        #swagger-ui {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem 2rem;
        }

        .swagger-ui .topbar {
            display: none;
        }

        .swagger-ui .info {
            margin: 30px 0;
        }

        .swagger-ui .info .title {
            font-size: 2rem;
            color: #1a1a2e;
        }

        .swagger-ui .info .description p {
            font-size: 1rem;
            line-height: 1.6;
        }

        .swagger-ui .opblock-tag {
            font-size: 1.25rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .swagger-ui .opblock {
            border-radius: 8px;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .swagger-ui .opblock .opblock-summary {
            padding: 12px 20px;
        }

        .swagger-ui .opblock.opblock-get {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.05);
        }

        .swagger-ui .opblock.opblock-get .opblock-summary {
            border-color: #10b981;
        }

        .swagger-ui .opblock.opblock-post {
            border-color: #3b82f6;
            background: color-mix(in srgb, var(--color-primary) 5%, transparent);
        }

        .swagger-ui .opblock.opblock-post .opblock-summary {
            border-color: #3b82f6;
        }

        .swagger-ui .opblock.opblock-put {
            border-color: #f59e0b;
            background: color-mix(in srgb, var(--color-warning) 5%, transparent);
        }

        .swagger-ui .opblock.opblock-put .opblock-summary {
            border-color: #f59e0b;
        }

        .swagger-ui .opblock.opblock-delete {
            border-color: #ef4444;
            background: color-mix(in srgb, var(--color-danger) 5%, transparent);
        }

        .swagger-ui .opblock.opblock-delete .opblock-summary {
            border-color: #ef4444;
        }

        .swagger-ui .btn.execute {
            background: #3b82f6;
            border-color: #3b82f6;
            border-radius: 6px;
        }

        .swagger-ui .btn.execute:hover {
            background: #2563eb;
        }

        .swagger-ui .btn.authorize {
            border-radius: 6px;
        }

        .swagger-ui select {
            border-radius: 6px;
        }

        .swagger-ui input[type=text] {
            border-radius: 6px;
        }

        .swagger-ui textarea {
            border-radius: 6px;
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            body {
                background: #111827;
            }

            .quick-start-card {
                background: #1f2937;
            }

            .quick-start h2 {
                color: #f9fafb;
            }

            .example-label {
                color: #d1d5db;
            }

            .swagger-ui .info .title {
                color: #f9fafb;
            }

            .swagger-ui .info .description p {
                color: #d1d5db;
            }

            .swagger-ui .opblock-tag {
                color: #f9fafb;
                border-color: #374151;
            }

            .swagger-ui .opblock-description-wrapper p {
                color: #d1d5db;
            }

            .swagger-ui table thead tr th {
                color: #d1d5db;
            }

            .swagger-ui .parameter__name {
                color: #f9fafb;
            }

            .swagger-ui .parameter__type {
                color: #9ca3af;
            }

            .swagger-ui .response-col_status {
                color: #f9fafb;
            }

            .swagger-ui .model-title {
                color: #f9fafb;
            }

            .swagger-ui .model {
                color: #d1d5db;
            }
        }
    </style>
</head>
<body>
    <header class="docs-header">
        <h1><?= htmlspecialchars($siteName) ?> API Documentation</h1>
        <a href="/">Back to Application</a>
    </header>

    <section class="quick-start">
        <div class="quick-start-card">
            <h2>Quick Start</h2>
            <div class="quick-start-grid">
                <div>
                    <p class="example-label">cURL</p>
                    <div class="code-example">
                        <span class="comment"># List all models</span><br>
                        curl -H <span class="string">"X-API-Key: YOUR_KEY"</span> \<br>
                        &nbsp;&nbsp;<?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?>/api/models
                    </div>
                </div>
                <div>
                    <p class="example-label">JavaScript</p>
                    <div class="code-example">
                        <span class="keyword">const</span> response = <span class="keyword">await</span> fetch(<span class="string">'/api/models'</span>, {<br>
                        &nbsp;&nbsp;headers: { <span class="string">'X-API-Key'</span>: <span class="string">'YOUR_KEY'</span> }<br>
                        });<br>
                        <span class="keyword">const</span> data = <span class="keyword">await</span> response.json();
                    </div>
                </div>
                <div>
                    <p class="example-label">PHP</p>
                    <div class="code-example">
                        $response = file_get_contents(<br>
                        &nbsp;&nbsp;<span class="string">'<?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?>/api/models'</span>,<br>
                        &nbsp;&nbsp;<span class="keyword">false</span>,<br>
                        &nbsp;&nbsp;stream_context_create([<span class="string">'http'</span> => [<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;<span class="string">'header'</span> => <span class="string">'X-API-Key: YOUR_KEY'</span><br>
                        &nbsp;&nbsp;]])<br>
                        );
                    </div>
                </div>
                <div>
                    <p class="example-label">Python</p>
                    <div class="code-example">
                        <span class="keyword">import</span> requests<br><br>
                        response = requests.get(<br>
                        &nbsp;&nbsp;<span class="string">'<?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?>/api/models'</span>,<br>
                        &nbsp;&nbsp;headers={<span class="string">'X-API-Key'</span>: <span class="string">'YOUR_KEY'</span>}<br>
                        )<br>
                        data = response.json()
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div id="swagger-ui"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function() {
            window.ui = SwaggerUIBundle({
                url: "<?= $specUrl ?>",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIBundle.SwaggerUIStandalonePreset
                ],
                layout: "BaseLayout",
                defaultModelsExpandDepth: 1,
                defaultModelExpandDepth: 1,
                docExpansion: "list",
                filter: true,
                showExtensions: false,
                showCommonExtensions: true,
                persistAuthorization: true,
                tryItOutEnabled: true
            });
        };
    </script>
</body>
</html>
