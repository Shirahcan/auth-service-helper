<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting...</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .loader-container {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .spinner {
            width: 50px;
            height: 50px;
            margin: 8px auto 20px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h2 {
            margin: 0 0 10px;
            color: #333;
            font-size: 24px;
        }
        p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        .success-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="loader-container">
        <div class="success-icon">✓</div>
        <h2>{{ $successMessage }}</h2>
        <p>Setting up your session...</p>
        <div class="spinner"></div>
    </div>

    <script>
        // Store token in localStorage for account switcher access
        const token = @json($token);
        const redirectUrl = @json($redirectUrl);

        console.log('🔐 Storing auth token in localStorage...');
        localStorage.setItem('auth_token', token);

        // Also store in sessionStorage as fallback
        sessionStorage.setItem('auth_token', token);

        console.log('✅ Auth token stored successfully');
        console.log('🔄 Redirecting to:', redirectUrl);

        // Redirect after a brief delay to ensure storage completes
        setTimeout(() => {
            window.location.href = redirectUrl;
        }, 500);
    </script>
</body>
</html>
