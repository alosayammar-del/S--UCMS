
<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور.';
    } else {
        $error = 'لم يتم تفعيل تسجيل الدخول بعد. سيتم ربط الحسابات بقاعدة البيانات في المرحلة التالية.';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#102c46">
<title>تسجيل الدخول | S-UCMS</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap');

:root {
    --navy: #102c46;
    --navy-light: #183d5c;
    --blue: #2675b9;
    --blue-light: #eaf3fb;
    --gold: #d6a84b;
    --text: #243447;
    --muted: #8190a1;
    --border: #e5ebf1;
    --white: #ffffff;
    --background: #f3f6fa;
    --danger: #c74343;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Cairo', sans-serif;
    min-height: 100vh;
    background: var(--background);
    color: var(--text);
}

.page {
    min-height: 100vh;
    display: grid;
    grid-template-columns: 1fr 1fr;
}

.visual-side {
    background: linear-gradient(145deg, #102c46 0%, #17496d 55%, #22658b 100%);
    color: white;
    padding: 55px 65px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

.visual-side::before,
.visual-side::after {
    content: "";
    position: absolute;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 50%;
}

.visual-side::before {
    width: 520px;
    height: 520px;
    top: -180px;
    right: -180px;
}

.visual-side::after {
    width: 420px;
    height: 420px;
    bottom: -180px;
    left: -130px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 15px;
    position: relative;
    z-index: 2;
}

.brand-icon {
    width: 55px;
    height: 55px;
    border-radius: 16px;
    background: rgba(255,255,255,.13);
    border: 1px solid rgba(255,255,255,.2);
    display: grid;
    place-items: center;
    font-size: 27px;
    color: #f1c66c;
    font-weight: 900;
}

.brand-name {
    font-size: 21px;
    font-weight: 800;
    line-height: 1.5;
}

.brand-subtitle {
    color: #b8cad9;
    font-size: 11px;
    letter-spacing: 1px;
}

.visual-content {
    position: relative;
    z-index: 2;
    max-width: 520px;
    margin: 55px auto;
}

.visual-label {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 8px 15px;
    border-radius: 30px;
    background: rgba(255,255,255,.1);
    color: #f1c66c;
    font-size: 12px;
    margin-bottom: 25px;
}

.visual-content h1 {
    font-size: clamp(30px, 4vw, 48px);
    line-height: 1.55;
    font-weight: 800;
    margin-bottom: 20px;
}

.visual-content h1 span {
    color: #e8bd68;
}

.visual-content p {
    color: #d0dce7;
    font-size: 15px;
    line-height: 2.2;
    max-width: 450px;
}

.features {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-top: 42px;
}

.feature {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 13px;
    font-size: 12px;
    color: #e5edf5;
}

.feature-icon {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: rgba(255,255,255,.12);
    color: #f1c66c;
    font-size: 17px;
}

.visual-footer {
    position: relative;
    z-index: 2;
    color: #a9bdce;
    font-size: 11px;
    text-align: center;
}

.form-side {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 45px 25px;
    background: #fff;
}

.login-container {
    width: 100%;
    max-width: 430px;
}

.mobile-brand {
    display: none;
}

.welcome {
    margin-bottom: 32px;
}

.welcome .tag {
    color: var(--blue);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 10px;
}

.welcome h2 {
    color: var(--navy);
    font-size: 29px;
    font-weight: 800;
    margin-bottom: 8px;
}

.welcome p {
    color: var(--muted);
    font-size: 13px;
    line-height: 2;
}

.form-group {
    margin-bottom: 21px;
}

.form-group label {
    display: block;
    color: #34465a;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 9px;
}

.input-wrapper {
    position: relative;
}

.input-wrapper input {
    width: 100%;
    height: 55px;
    border: 1px solid var(--border);
    background: #fbfcfe;
    border-radius: 11px;
    padding: 0 48px 0 45px;
    font-family: inherit;
    font-size: 13px;
    color: var(--text);
    outline: none;
    transition: .25s;
}

.input-wrapper input:focus {
    border-color: var(--blue);
    background: white;
    box-shadow: 0 0 0 4px rgba(38,117,185,.09);
}

.input-wrapper input::placeholder {
    color: #aab5c1;
}

.input-icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #91a1b1;
    font-size: 17px;
}

.password-toggle {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: #8293a4;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
}

.form-options {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 5px 0 25px;
    font-size: 12px;
}

.remember {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #657487;
    cursor: pointer;
}

.remember input {
    accent-color: var(--blue);
    width: 15px;
    height: 15px;
}

.forgot {
    color: var(--blue);
    text-decoration: none;
    font-weight: 700;
}

.login-button {
    width: 100%;
    height: 55px;
    border: none;
    border-radius: 11px;
    background: linear-gradient(100deg, #174b73, #2675b9);
    color: white;
    font-family: inherit;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 8px 18px rgba(28,91,139,.17);
    transition: .25s;
}

.login-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 11px 23px rgba(28,91,139,.25);
}

.login-button:active {
    transform: scale(.99);
}

.error-message {
    background: #fff0f0;
    border: 1px solid #f3cccc;
    color: var(--danger);
    border-radius: 10px;
    padding: 12px 15px;
    font-size: 12px;
    line-height: 1.8;
    margin-bottom: 20px;
}

.security-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    color: #8998a8;
    font-size: 11px;
    margin-top: 24px;
}

.security-note span {
    color: #3b9a79;
    font-size: 15px;
}

.separator {
    display: flex;
    align-items: center;
    gap: 13px;
    color: #a2adba;
    font-size: 11px;
    margin: 28px 0;
}

.separator::before,
.separator::after {
    content: "";
    height: 1px;
    flex: 1;
    background: var(--border);
}

.support {
    text-align: center;
    color: #8492a1;
    font-size: 12px;
    line-height: 2;
}

.support a {
    color: var(--blue);
    text-decoration: none;
    font-weight: 700;
}

.form-footer {
    text-align: center;
    color: #a2adba;
    font-size: 10px;
    margin-top: 38px;
}

@media (max-width: 850px) {
    .page {
        grid-template-columns: 1fr;
    }

    .visual-side {
        display: none;
    }

    .form-side {
        min-height: 100vh;
        padding: 35px 22px;
    }

    .login-container {
        max-width: 440px;
    }

    .mobile-brand {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-bottom: 48px;
    }

    .mobile-brand .brand-icon {
        background: var(--navy);
        color: #e8bd68;
    }

    .mobile-brand .brand-name {
        color: var(--navy);
        font-size: 19px;
    }

    .mobile-brand .brand-subtitle {
        color: #8190a1;
    }

    .welcome h2 {
        font-size: 26px;
    }
}

@media (max-width: 380px) {
    .form-side {
        padding: 25px 17px;
    }

    .welcome h2 {
        font-size: 23px;
    }

    .form-options {
        font-size: 11px;
    }
}
</style>
</head>

<body>

<div class="page">

    <section class="visual-side">

        <div class="brand">
            <div class="brand-icon">U</div>
            <div>
                <div class="brand-name">S-UCMS</div>
                <div class="brand-subtitle">SMART UNIVERSITY CAMPUS MANAGEMENT</div>
            </div>
        </div>

        <div class="visual-content">

            <div class="visual-label">
                <span>✦</span>
                <span>النظام الجامعي الذكي المتكامل</span>
            </div>

            <h1>
                إدارة جامعية أكثر
                <span>ذكاء وتنظيما</span>
            </h1>

            <p>
                منصة رقمية موحدة تجمع الخدمات الأكاديمية والإدارية
                في بيئة إلكترونية متكاملة، لتسهيل الوصول إلى المعلومات
                وتحسين تجربة جميع أفراد المجتمع الجامعي.
            </p>

            <div class="features">

                <div class="feature">
                    <div class="feature-icon">▦</div>
                    <span>إدارة المقررات والجداول</span>
                </div>

                <div class="feature">
                    <div class="feature-icon">◷</div>
                    <span>متابعة الحضور والغياب</span>
                </div>

                <div class="feature">
                    <div class="feature-icon">▤</div>
                    <span>الخدمات والطلبات الجامعية</span>
                </div>

                <div class="feature">
                    <div class="feature-icon">♧</div>
                    <span>التنبيهات الأكاديمية الذكية</span>
                </div>

            </div>

        </div>

        <div class="visual-footer">
            S-UCMS © 2026 | جميع الحقوق محفوظة
        </div>

    </section>

    <main class="form-side">

        <div class="login-container">

            <div class="mobile-brand">
                <div class="brand-icon">U</div>
                <div>
                    <div class="brand-name">S-UCMS</div>
                    <div class="brand-subtitle">SMART UNIVERSITY CAMPUS MANAGEMENT</div>
                </div>
            </div>

            <div class="welcome">
                <div class="tag">مرحبا بعودتك</div>
                <h2>تسجيل الدخول</h2>
                <p>
                    يرجى إدخال بيانات حسابك للوصول إلى النظام الجامعي.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">

                <div class="form-group">
                    <label for="email">البريد الإلكتروني</label>

                    <div class="input-wrapper">
                        <span class="input-icon">✉</span>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="أدخل البريد الإلكتروني"
                            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                            autocomplete="username"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">كلمة المرور</label>

                    <div class="input-wrapper">
                        <span class="input-icon">♙</span>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="أدخل كلمة المرور"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            id="togglePassword"
                        >
                            إظهار
                        </button>
                    </div>
                </div>

                <div class="form-options">

                    <label class="remember">
                        <input type="checkbox" name="remember">
                        <span>تذكرني</span>
                    </label>

                    <a href="#" class="forgot" id="forgotPassword">
                        نسيت كلمة المرور؟
                    </a>

                </div>

                <button type="submit" class="login-button">
                    تسجيل الدخول
                    <span> ←</span>
                </button>

            </form>

            <div class="security-note">
                <span>♧</span>
                <span>بيئة جامعية رقمية آمنة</span>
            </div>

            <div class="separator">
                <span>نظام إدارة الحرم الجامعي</span>
            </div>

            <div class="support">
                تواجه مشكلة في الدخول؟
                <br>
                يرجى التواصل مع إدارة النظام.
            </div>

            <div class="form-footer">
                S-UCMS © 2026 - جميع الحقوق محفوظة
            </div>

        </div>

    </main>

</div>

<script>
const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

togglePassword.addEventListener('click', function () {
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        togglePassword.textContent = 'إخفاء';
    } else {
        passwordInput.type = 'password';
        togglePassword.textContent = 'إظهار';
    }
});

document.getElementById('forgotPassword').addEventListener('click', function (event) {
    event.preventDefault();
    alert('يرجى التواصل مع إدارة النظام لاستعادة كلمة المرور.');
});
</script>

</body>
</html>
