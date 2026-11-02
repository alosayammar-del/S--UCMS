<?php

session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    exit('غير مصرح لك بالوصول إلى هذه الصفحة.');
}

$userId = (int) $_SESSION['user_id'];

$checkAdmin = $pdo->prepare(
    "SELECT id, role, status FROM users WHERE id = ? LIMIT 1"
);
$checkAdmin->execute([$userId]);
$admin = $checkAdmin->fetch();

if (
    !$admin ||
    $admin['role'] !== 'administrator' ||
    $admin['status'] !== 'active'
) {
    session_destroy();
    header('Location: index.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['courses_csrf'])) {
    $_SESSION['courses_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['courses_csrf'], $csrfToken)) {
        $error = 'انتهت صلاحية الطلب. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $courseCode = trim($_POST['course_code'] ?? '');
            $courseName = trim($_POST['course_name'] ?? '');
            $creditHours = (int) ($_POST['credit_hours'] ?? 0);
            $description = trim($_POST['description'] ?? '');

            if ($courseCode === '' || $courseName === '') {
                $error = 'يرجى إدخال رمز المقرر واسم المقرر.';
            } elseif (strlen($courseCode) > 50) {
                $error = 'رمز المقرر يجب ألا يتجاوز 50 حرفا.';
            } elseif (strlen($courseName) > 150) {
                $error = 'اسم المقرر يجب ألا يتجاوز 150 حرفا.';
            } elseif ($creditHours < 1 || $creditHours > 255) {
                $error = 'عدد الساعات يجب أن يكون بين 1 و255.';
            } else {
                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare(
                            "INSERT INTO courses
                            (course_code, course_name, credit_hours, description)
                            VALUES (?, ?, ?, ?)"
                        );

                        $stmt->execute([
                            $courseCode,
                            $courseName,
                            $creditHours,
                            $description !== '' ? $description : null
                        ]);

                        $success = 'تمت إضافة المقرر بنجاح.';
                    } else {
                        if ($id <= 0) {
                            throw new RuntimeException('رقم المقرر غير صحيح.');
                        }

                        $stmt = $pdo->prepare(
                            "UPDATE courses
                            SET course_code = ?, course_name = ?,
                                credit_hours = ?, description = ?
                            WHERE id = ?"
                        );

                        $stmt->execute([
                            $courseCode,
                            $courseName,
                            $creditHours,
                            $description !== '' ? $description : null,
                            $id
                        ]);

                        $success = 'تم تحديث بيانات المقرر بنجاح.';
                    }
                } catch (PDOException $ex) {
                    if ($ex->getCode() === '23000') {
                        $error = 'رمز المقرر مستخدم بالفعل. يرجى اختيار رمز آخر.';
                    } else {
                        error_log($ex->getMessage());
                        $error = 'حدث خطأ أثناء حفظ بيانات المقرر.';
                    }
                } catch (RuntimeException $ex) {
                    $error = $ex->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                $error = 'رقم المقرر غير صحيح.';
            } else {
                try {
                    $relatedTables = [
                        'enrollments',
                        'course_instructors',
                        'assignments',
                        'schedules'
                    ];

                    $hasRelations = false;

                    foreach ($relatedTables as $table) {
                        $check = $pdo->prepare(
                            "SELECT COUNT(*) FROM `$table` WHERE course_id = ?"
                        );
                        $check->execute([$id]);

                        if ((int) $check->fetchColumn() > 0) {
                            $hasRelations = true;
                            break;
                        }
                    }

                    if ($hasRelations) {
                        $error = 'لا يمكن حذف هذا المقرر لأنه مرتبط بتسجيلات أو واجبات أو جداول أو أعضاء هيئة تدريس.';
                    } else {
                        $stmt = $pdo->prepare(
                            "DELETE FROM courses WHERE id = ?"
                        );
                        $stmt->execute([$id]);

                        $success = $stmt->rowCount() > 0
                            ? 'تم حذف المقرر بنجاح.'
                            : 'المقرر غير موجود.';
                    }
                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    $error = 'تعذر حذف المقرر بسبب ارتباطه ببيانات أخرى.';
                }
            }
        } else {
            $error = 'العملية المطلوبة غير صحيحة.';
        }

        $_SESSION['courses_csrf'] = bin2hex(random_bytes(32));
    }
}

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM courses
         WHERE course_code LIKE ?
         OR course_name LIKE ?
         ORDER BY id DESC"
    );

    $term = '%' . $search . '%';
    $stmt->execute([$term, $term]);
} else {
    $stmt = $pdo->query(
        "SELECT * FROM courses ORDER BY id DESC"
    );
}

$courses = $stmt->fetchAll();

$totalCourses = (int) $pdo->query(
    "SELECT COUNT(*) FROM courses"
)->fetchColumn();

$totalHours = (int) $pdo->query(
    "SELECT COALESCE(SUM(credit_hours), 0) FROM courses"
)->fetchColumn();

$editCourse = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    if ($editId > 0) {
        $editStmt = $pdo->prepare(
            "SELECT * FROM courses WHERE id = ? LIMIT 1"
        );
        $editStmt->execute([$editId]);
        $editCourse = $editStmt->fetch();

        if (!$editCourse) {
            $error = 'المقرر المطلوب تعديله غير موجود.';
        }
    }
}

$isEditing = (bool) $editCourse;

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المقررات | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f7fb;
            color: #243247;
        }

        .header {
            background: #172b4d;
            color: white;
            padding: 22px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            margin: 0;
            font-size: 22px;
        }

        .header a {
            color: white;
            text-decoration: none;
            background: #29466f;
            padding: 10px 16px;
            border-radius: 8px;
        }

        .container {
            width: 92%;
            max-width: 1200px;
            margin: 30px auto;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .stat span {
            color: #64748b;
            font-size: 14px;
        }

        .stat strong {
            display: block;
            font-size: 28px;
            margin-top: 10px;
            color: #176b87;
        }

        .panel {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .panel h2 {
            margin: 0 0 20px;
            font-size: 19px;
            color: #172b4d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 14px;
            font-weight: bold;
        }

        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7dfeb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            outline: none;
        }

        input:focus, textarea:focus {
            border-color: #2585a5;
        }

        textarea {
            resize: vertical;
            min-height: 90px;
        }

        button, .btn {
            border: 0;
            border-radius: 8px;
            padding: 11px 17px;
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .primary {
            background: #176b87;
            color: white;
        }

        .secondary {
            background: #e8edf5;
            color: #243247;
        }

        .edit {
            background: #e8f2ff;
            color: #245a9a;
        }

        .delete {
            background: #fff0f0;
            color: #c0392b;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .message {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .success {
            background: #e8f7ee;
            color: #176b3a;
        }

        .error {
            background: #fff0f0;
            color: #a52a2a;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }

        .search-form input {
            flex: 1;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            padding: 14px 12px;
            text-align: right;
            border-bottom: 1px solid #edf0f5;
            font-size: 14px;
        }

        th {
            background: #f5f8fc;
            color: #52647d;
        }

        td {
            line-height: 1.7;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #78869a;
        }

        .description {
            max-width: 230px;
            color: #68778c;
        }

        @media (max-width: 650px) {
            .stats {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat {
                padding: 15px;
            }

            .stat strong {
                font-size: 23px;
            }

            .panel {
                padding: 17px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .search-form {
                flex-direction: column;
            }

            .header {
                padding: 18px 4%;
            }

            .header h1 {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>إدارة المقررات الدراسية</h1>
    <a href="dashboard.php">العودة إلى لوحة التحكم</a>
</header>

<main class="container">

    <section class="stats">
        <div class="stat">
            <span>إجمالي المقررات</span>
            <strong><?= e($totalCourses) ?></strong>
        </div>

        <div class="stat">
            <span>إجمالي الساعات المعتمدة</span>
            <strong><?= e($totalHours) ?></strong>
        </div>
    </section>

    <?php if ($success !== ''): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2><?= $isEditing ? 'تعديل بيانات المقرر' : 'إضافة مقرر جديد' ?></h2>

        <form method="POST" action="manage_courses.php<?= $isEditing ? '?edit=' . (int) $editCourse['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['courses_csrf']) ?>">
            <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'add' ?>">

            <?php if ($isEditing): ?>
                <input type="hidden" name="id" value="<?= (int) $editCourse['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">

                <div class="field">
                    <label for="course_code">رمز المقرر</label>
                    <input
                        type="text"
                        id="course_code"
                        name="course_code"
                        maxlength="50"
                        required
                        value="<?= e($editCourse['course_code'] ?? '') ?>"
                        placeholder="مثال: IS101"
                    >
                </div>

                <div class="field">
                    <label for="course_name">اسم المقرر</label>
                    <input
                        type="text"
                        id="course_name"
                        name="course_name"
                        maxlength="150"
                        required
                        value="<?= e($editCourse['course_name'] ?? '') ?>"
                        placeholder="أدخل اسم المقرر"
                    >
                </div>

                <div class="field">
                    <label for="credit_hours">عدد الساعات المعتمدة</label>
                    <input
                        type="number"
                        id="credit_hours"
                        name="credit_hours"
                        min="1"
                        max="255"
                        required
                        value="<?= e($editCourse['credit_hours'] ?? 3) ?>"
                    >
                </div>

                <div class="field full">
                    <label for="description">وصف المقرر</label>
                    <textarea
                        id="description"
                        name="description"
                        placeholder="وصف مختصر للمقرر (اختياري)"
                    ><?= e($editCourse['description'] ?? '') ?></textarea>
                </div>

                <div class="field full">
                    <div class="actions">
                        <button type="submit" class="primary">
                            <?= $isEditing ? 'حفظ التعديلات' : 'إضافة المقرر' ?>
                        </button>

                        <?php if ($isEditing): ?>
                            <a href="manage_courses.php" class="btn secondary">إلغاء التعديل</a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </form>
    </section>

    <section class="panel">
        <h2>قائمة المقررات</h2>

        <form method="GET" action="manage_courses.php" class="search-form">
            <input
                type="search"
                name="search"
                value="<?= e($search) ?>"
                placeholder="ابحث برمز المقرر أو اسمه..."
            >
            <button type="submit" class="primary">بحث</button>
            <a href="manage_courses.php" class="btn secondary">عرض الكل</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رمز المقرر</th>
                        <th>اسم المقرر</th>
                        <th>الساعات</th>
                        <th>الوصف</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($courses) > 0): ?>
                    <?php foreach ($courses as $index => $course): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= e($course['course_code']) ?></td>
                            <td><?= e($course['course_name']) ?></td>
                            <td><?= e($course['credit_hours']) ?></td>
                            <td class="description">
                                <?= e($course['description'] ?: '—') ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a
                                        class="btn edit"
                                        href="manage_courses.php?edit=<?= (int) $course['id'] ?>"
                                    >تعديل</a>

                                    <form
                                        method="POST"
                                        action="manage_courses.php"
                                        onsubmit="return confirm('هل أنت متأكد من حذف هذا المقرر؟');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['courses_csrf']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $course['id'] ?>">
                                        <button type="submit" class="delete">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty">لا توجد مقررات لعرضها.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
