
<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: pages/dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare(
        "SELECT * FROM users WHERE username = ?"
    );

    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        header("Location: pages/dashboard.php");
        exit;

    } else {
        $error = "Invalid username or password!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Bakery System | Login</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', Arial, sans-serif;
            min-height: 100vh;
            background: #f5f0e9;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 30px;
        }

        .login-container {
            width: 100%;
            max-width: 1100px;
            min-height: 620px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            background: white;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(49, 31, 17, 0.15);
        }

        /* LEFT IMAGE SECTION */

        .image-section {
            position: relative;
            background-image:
                linear-gradient(
                    to bottom,
                    rgba(35, 21, 12, 0.20),
                    rgba(35, 21, 12, 0.78)
                ),
                url('https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=1400&q=85');

            background-size: cover;
            background-position: center;
            display: flex;
            align-items: flex-end;
            padding: 55px;
            color: white;
        }

        .image-content {
            position: relative;
            z-index: 1;
            max-width: 430px;
        }

        .brand-label {
            display: inline-block;
            padding: 8px 15px;
            border: 1px solid rgba(255,255,255,0.55);
            border-radius: 30px;
            font-size: 11px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 22px;
            background: rgba(0,0,0,0.15);
        }

        .image-content h1 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 45px;
            line-height: 1.2;
            margin-bottom: 18px;
        }

        .image-content p {
            font-size: 15px;
            line-height: 1.8;
            color: #f2e9df;
            max-width: 380px;
        }

        .image-divider {
            width: 65px;
            height: 3px;
            background: #d9a66b;
            margin: 22px 0;
        }

        /* RIGHT LOGIN SECTION */

        .form-section {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 65px 55px;
        }

        .logo {
            width: 55px;
            height: 55px;
            background: #f4e9dc;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            margin-bottom: 25px;
        }

        .heading .eyebrow {
            color: #a36d3f;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 12px;
        }

        .heading h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 34px;
            color: #38271b;
            margin-bottom: 12px;
        }

        .heading p {
            font-size: 14px;
            color: #82776e;
            line-height: 1.7;
            margin-bottom: 35px;
        }

        .error {
            background: #fff0ef;
            color: #a92b24;
            border: 1px solid #f4ceca;
            border-radius: 8px;
            padding: 13px;
            font-size: 13px;
            margin-bottom: 22px;
        }

        .form-group {
            margin-bottom: 23px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #49372a;
            margin-bottom: 9px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            height: 52px;
            padding: 0 45px 0 15px;
            border: 1px solid #e3dcd4;
            border-radius: 8px;
            background: #fdfbf9;
            outline: none;
            font-family: inherit;
            font-size: 14px;
            color: #38271b;
            transition: 0.25s;
        }

        .input-wrapper input:focus {
            border-color: #9b663b;
            background: white;
            box-shadow: 0 0 0 3px rgba(155,102,59,0.10);
        }

        .input-wrapper input::placeholder {
            color: #aaa19a;
        }

        .input-symbol {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a38b76;
            font-size: 17px;
            pointer-events: none;
        }

        .password-input {
            padding-right: 75px !important;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #95643e;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            width: auto;
            padding: 5px;
        }

        .toggle-password:hover {
            color: #57371f;
        }

        .login-button {
            width: 100%;
            height: 53px;
            margin-top: 8px;
            border: none;
            border-radius: 8px;
            background: #754a2a;
            color: white;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.25s;
        }

        .login-button:hover {
            background: #56351e;
            box-shadow: 0 7px 18px rgba(86,53,30,0.18);
            transform: translateY(-2px);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .footer {
            margin-top: 45px;
            padding-top: 20px;
            border-top: 1px solid #eee8e2;
            text-align: center;
            color: #a39a92;
            font-size: 11px;
            line-height: 1.7;
        }

        /* TABLET AND MOBILE */

        @media (max-width: 850px) {

            body {
                padding: 20px;
            }

            .login-container {
                max-width: 500px;
                grid-template-columns: 1fr;
            }

            .image-section {
                min-height: 260px;
                padding: 30px;
                background-position: center 55%;
            }

            .image-content h1 {
                font-size: 32px;
            }

            .image-content p {
                font-size: 13px;
            }

            .image-divider {
                margin: 13px 0;
            }

            .brand-label {
                margin-bottom: 13px;
            }

            .form-section {
                padding: 40px 35px;
            }

            .heading h2 {
                font-size: 29px;
            }

            .heading p {
                margin-bottom: 25px;
            }

            .footer {
                margin-top: 30px;
            }
        }

        @media (max-width: 480px) {

            body {
                padding: 12px;
            }

            .login-container {
                border-radius: 13px;
            }

            .image-section {
                min-height: 220px;
                padding: 25px;
            }

            .image-content h1 {
                font-size: 27px;
            }

            .form-section {
                padding: 32px 23px;
            }

            .heading h2 {
                font-size: 27px;
            }
        }

    </style>
</head>

<body>

<div class="login-container">

    <!-- BAKERY IMAGE AND BRANDING -->
    <section class="image-section">

        <div class="image-content">

            <div class="brand-label">
                Bakery Sales & Management
            </div>

            <h1>Freshly Baked.<br>Simply Managed.</h1>

            <div class="image-divider"></div>

            <p>
                Manage your bakery products, monitor inventory,
                and keep track of your sales in one convenient system.
            </p>

        </div>

    </section>


    <!-- LOGIN FORM -->
    <section class="form-section">

        <div class="logo">🥐</div>

        <div class="heading">

            <div class="eyebrow">
                Welcome Back
            </div>

            <h2>Sign In</h2>

            <p>
                Enter your username and password to access
                the Bakery Sales and Stock Monitoring System.
            </p>

        </div>


        <?php if ($error): ?>

            <div class="error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php endif; ?>


        <form method="POST" id="loginForm" autocomplete="off">

            <div class="form-group">

                <label for="username">
                    Username
                </label>

                <div class="input-wrapper">

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter your username"
                        autocomplete="off"
                        required
                    >

                    <span class="input-symbol">👤</span>

                </div>

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <div class="input-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="password-input"
                        placeholder="Enter your password"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        id="togglePassword"
                    >
                        SHOW
                    </button>

                </div>

            </div>


            <button type="submit" class="login-button">
                Sign In to Your Account →
            </button>

        </form>


        <div class="footer">
            Bakery Sales and Stock Monitoring System<br>
            © <?= date('Y') ?> All Rights Reserved.
        </div>

    </section>

</div>


<script>

// SHOW / HIDE PASSWORD
const passwordInput = document.getElementById("password");
const togglePassword = document.getElementById("togglePassword");

togglePassword.addEventListener("click", function() {

    if (passwordInput.type === "password") {

        passwordInput.type = "text";
        togglePassword.textContent = "HIDE";

    } else {

        passwordInput.type = "password";
        togglePassword.textContent = "SHOW";

    }

});


// CLEAR INPUTS AFTER LOGIN ATTEMPT
const loginForm = document.getElementById("loginForm");

loginForm.addEventListener("submit", function() {

    setTimeout(function() {

        loginForm.reset();

        passwordInput.type = "password";
        togglePassword.textContent = "SHOW";

    }, 0);

});

</script>

</body>
</html>
