<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting to EPS Payment Gateway...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            color: #1e293b;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            padding: 2.5rem;
            text-align: center;
            max-width: 420px;
            width: 90%;
        }
        .spinner {
            border: 4px solid #f1f5f9;
            border-top: 4px solid #0284c7;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h2 { margin: 0 0 0.5rem; font-size: 1.25rem; font-weight: 600; }
        p { color: #64748b; font-size: 0.875rem; margin: 0 0 1.5rem; }
        .btn {
            display: inline-block;
            background: #0284c7;
            color: white;
            padding: 0.65rem 1.5rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <h2>Redirecting to EPS Payment Gateway</h2>
        <p>Please wait while we connect you securely to the Easy Payment System...</p>
        <a href="{{ $redirect_url ?? '#' }}" class="btn" id="btn-redirect">Click here if not redirected</a>
    </div>

    @if(!empty($redirect_url))
    <script>
        setTimeout(function() {
            window.location.href = "{{ $redirect_url }}";
        }, 500);
    </script>
    @endif
</body>
</html>

