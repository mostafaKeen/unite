<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connecting Unite EMR...</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #090d16;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #f8fafc;
        }
        .container {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(0, 165, 181, 0.25);
            border-radius: 20px;
            padding: 40px;
            max-width: 440px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 40px -10px rgba(0, 165, 181, 0.2);
            animation: fadeIn 0.3s ease-out;
        }
        .logo-box {
            display: inline-flex;
            align-items: center;
            background: #00a5b5;
            color: white;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0, 165, 181, 0.3);
        }
        .spinner {
            width: 38px;
            height: 38px;
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-top: 3px solid #00a5b5;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        h2 { 
            font-size: 17px; 
            font-weight: 700; 
            margin-bottom: 8px; 
            color: #f1f5f9;
        }
        p { 
            font-size: 13px; 
            color: #94a3b8; 
            line-height: 1.5;
        }
        .fallback { 
            margin-top: 20px; 
            font-size: 12px;
            color: #64748b;
        }
        .fallback a {
            color: #00a5b5;
            text-decoration: none;
            font-weight: 600;
        }
        .fallback a:hover {
            text-decoration: underline;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-box">
            <span style="font-size: 18px; letter-spacing: -0.5px;">Unite</span>
            <span style="font-size: 18px; margin-left: 2px;">+</span>
        </div>
        <div class="spinner"></div>
        <h2>Authenticating automatically...</h2>
        <p>Connecting your Bitrix24 portal with Unite EMR Healthcare Platform.</p>
        <p class="fallback" id="fallback-msg">
            Taking longer than expected? <a href="{{ $redirectUrl }}">Click here to continue</a>.
        </p>
    </div>

    <!-- Bitrix24 JS SDK -->
    <script src="//api.bitrix24.com/api/v1/"></script>

    <script>
        (function() {
            var redirectUrl = @json($redirectUrl);
            var finished = false;

            // Fallback redirect after 3.5 seconds in case BX24 is outside iframe
            var fallbackTimer = setTimeout(function() {
                if (!finished) {
                    console.log('[Unite EMR] Fallback redirect triggered.');
                    window.location.href = redirectUrl;
                }
            }, 3500);

            try {
                if (typeof BX24 !== 'undefined') {
                    BX24.init(function() {
                        try {
                            BX24.fitWindow();
                            BX24.installFinish();
                        } catch(e) {
                            console.log('[Unite EMR] installFinish exception:', e);
                        }
                        finished = true;
                        clearTimeout(fallbackTimer);
                        setTimeout(function() {
                            window.location.href = redirectUrl;
                        }, 200);
                    });
                } else {
                    window.location.href = redirectUrl;
                }
            } catch (e) {
                console.error('[Unite EMR] Bitrix24 SDK error:', e);
                clearTimeout(fallbackTimer);
                window.location.href = redirectUrl;
            }
        })();
    </script>
</body>
</html>
