
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

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

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
    position: relative;
}

.logo {
    width: 58px;
    height: 58px;
    background: #f4e9dc;
    border: 1px solid #ead8c6;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 27px;
    margin-bottom: 25px;
    box-shadow: 0 5px 15px rgba(117,74,42,0.08);
    transition: 0.3s ease;
}

.logo:hover {
    transform: translateY(-4px) rotate(-5deg);
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

/* ERROR MESSAGE */

.error {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff0ef;
    color: #a92b24;
    border: 1px solid #f4ceca;
    border-radius: 10px;
    padding: 13px;
    font-size: 12px;
    margin-bottom: 22px;
    animation: errorShake 0.35s ease;
}

.error-icon {
    width: 19px;
    height: 19px;
    flex-shrink: 0;
}

@keyframes errorShake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-4px); }
    75% { transform: translateX(4px); }
}

/* FORM */

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
    height: 54px;
    padding: 0 48px;
    border: 1px solid #e3dcd4;
    border-radius: 10px;
    background: #fdfbf9;
    outline: none;
    font-family: inherit;
    font-size: 14px;
    color: #38271b;
    transition: all 0.25s ease;
}

.input-wrapper input:focus {
    border-color: #9b663b;
    background: white;
    box-shadow: 0 0 0 4px rgba(155,102,59,0.10);
}

.input-wrapper input::placeholder {
    color: #aaa19a;
}

.input-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    width: 19px;
    height: 19px;
    color: #a38b76;
    pointer-events: none;
    transition: all 0.25s ease;
}

.input-wrapper:focus-within .input-icon {
    color: #754a2a;
    transform: translateY(-50%) scale(1.12);
}

/* PASSWORD TOGGLE */

.password-input {
    padding-right: 53px !important;
}

.toggle-password {
    position: absolute;
    right: 9px;
    top: 50%;
    transform: translateY(-50%);
    width: 37px;
    height: 37px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    border-radius: 8px;
    color: #95643e;
    cursor: pointer;
    transition: 0.2s ease;
}

.toggle-password:hover {
    background: #f4e9dc;
    color: #57371f;
}

.toggle-password svg {
    width: 19px;
    height: 19px;
}

/* LOGIN BUTTON */

.login-button {
    width: 100%;
    height: 54px;
    margin-top: 8px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #825334, #603a24);
    color: white;
    font-family: inherit;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s ease;
    box-shadow: 0 7px 17px rgba(86,53,30,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.login-button:hover {
    background: linear-gradient(135deg, #70452b, #56351e);
    box-shadow: 0 10px 23px rgba(86,53,30,0.22);
    transform: translateY(-2px);
}

.login-button:active {
    transform: scale(0.985);
}

.login-button:disabled {
    opacity: 0.8;
    cursor: wait;
    transform: none;
}

/* BUTTON ARROW */

.button-arrow {
    width: 18px;
    height: 18px;
    transition: transform 0.25s ease;
}

.login-button:hover .button-arrow {
    transform: translateX(5px);
}

/* LOADING */

.loading-spinner {
    width: 19px;
    height: 19px;
    border: 2px solid rgba(255,255,255,0.35);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* SECURITY NOTE */

.secure-note {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 19px;
    color: #998c81;
    font-size: 11px;
}

.secure-note svg {
    width: 16px;
    height: 16px;
    color: #82956e;
}

/* FOOTER */

.footer {
    margin-top: 40px;
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

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}

</style>
</head>

<body>

<div class="login-container">

    <!-- LEFT IMAGE SECTION -->

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


    <!-- RIGHT LOGIN SECTION -->

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

            <div class="error" role="alert">

                <svg class="error-icon" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M12 8v4"></path>
                    <path d="M12 16h.01"></path>
                </svg>

                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

            </div>

        <?php endif; ?>


        <!-- LOGIN FORM -->

        <form method="POST" id="loginForm" autocomplete="off">

            <div class="form-group">

                <label for="username">Username</label>

                <div class="input-wrapper">

                    <svg class="input-icon" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M5 21v-2a7 7 0 0 1 14 0v2"></path>
                    </svg>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter your username"
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        maxlength="100"
                        required
                        autofocus
                    >

                </div>

            </div>


            <div class="form-group">

                <label for="password">Password</label>

                <div class="input-wrapper">

                    <svg class="input-icon" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round">
                        <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                        <path d="M8 10V7a4 4 0 1 1 8 0v3"></path>
                        <path d="M12 14v3"></path>
                    </svg>

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
                        aria-label="Show password"
                        title="Show password"
                    >

                        <svg id="eyeIcon" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round"
                             stroke-linejoin="round">

                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                            <circle cx="12" cy="12" r="3"></circle>

                        </svg>

                    </button>

                </div>

            </div>


            <!-- SIGN IN BUTTON -->

            <button type="submit" class="login-button" id="loginButton">

                <span id="buttonText">Sign In to Your Account</span>

                <svg class="button-arrow" id="buttonArrow"
                     viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">

                    <path d="M5 12h14"></path>
                    <path d="m12 5 7 7-7 7"></path>

                </svg>

            </button>

        </form>


        <!-- SECURITY NOTE -->

        <div class="secure-note">

            <svg viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">

                <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"></path>
                <path d="m9 12 2 2 4-4"></path>

            </svg>

            <span>Your login session is protected</span>

        </div>


        <!-- FOOTER -->

        <div class="footer">
            Bakery Sales and Stock Monitoring System<br>
            © <?= date('Y') ?> All Rights Reserved.
        </div>

    </section>

</div>


<script>

// PASSWORD SHOW / HIDE

const passwordInput = document.getElementById("password");
const togglePassword = document.getElementById("togglePassword");
const eyeIcon = document.getElementById("eyeIcon");

togglePassword.addEventListener("click", function() {

    const showPassword = passwordInput.type === "password";

    passwordInput.type = showPassword ? "text" : "password";

    if (showPassword) {

        eyeIcon.innerHTML = `
            <path d="M3 3l18 18"></path>
            <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>
            <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a16 16 0 0 1-3 3.8"></path>
            <path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 4-.8"></path>
        `;

        togglePassword.setAttribute("aria-label", "Hide password");
        togglePassword.title = "Hide password";

    } else {

        eyeIcon.innerHTML = `
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
            <circle cx="12" cy="12" r="3"></circle>
        `;

        togglePassword.setAttribute("aria-label", "Show password");
        togglePassword.title = "Show password";

    }

});


// LOGIN SUBMISSION INTERACTION

const loginForm = document.getElementById("loginForm");
const loginButton = document.getElementById("loginButton");
const buttonText = document.getElementById("buttonText");
const buttonArrow = document.getElementById("buttonArrow");

loginForm.addEventListener("submit", function() {

    if (!loginForm.checkValidity()) {
        return;
    }

    loginButton.disabled = true;

    buttonArrow.style.display = "none";

    buttonText.innerHTML = `
        <span style="
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:10px;
        ">
            <span class="loading-spinner"></span>
            Authenticating...
        </span>
    `;

});


// RESET LOGIN FORM WHEN PAGE LOADS OR IS RESTORED

function resetLoginForm() {

    document.getElementById("username").value = "";
    passwordInput.value = "";

    // Reset password visibility
    passwordInput.type = "password";

    eyeIcon.innerHTML = `
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
        <circle cx="12" cy="12" r="3"></circle>
    `;

    togglePassword.setAttribute("aria-label", "Show password");
    togglePassword.title = "Show password";

    // Reset login button
    loginButton.disabled = false;

    buttonText.textContent = "Sign In to Your Account";

    buttonArrow.style.display = "block";

}

window.addEventListener("pageshow", resetLoginForm);

</script>

</body>
</html>
