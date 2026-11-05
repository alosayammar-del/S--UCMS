<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: dashboard.php');
    exit;
}

$facultyId = (int) $_SESSION['user_id'];

$success = '';
$error = '';

$courses = [];
$assignments = [];

$selectedCourse = (int) (
    $_GET['course_id'] ?? $_POST['course_id'] ?? 0
);

$editId = (int) ($_GET['edit'] ?? 0);

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {

    // جلب المقررات المسندة لعضو هيئة التدريس
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            c.id,
            c.course_code,
            c.course_name
        FROM course_instructors ci
        INNER JOIN courses c
            ON c.id = ci.course_id
        WHERE ci.faculty_user_id = ?
        ORDER BY c.course_name
    ");

    $stmt->execute([$facultyId]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // التحقق من المقرر المحدد
    $courseExists = false;

    foreach ($courses as $course) {
        if ((int)$course['id'] === $selectedCourse) {
            $courseExists = true;
            break;
        }
    }

    if (!$courseExists && !empty($courses)) {
        $selectedCourse = (int)$courses[0]['id'];
        $courseExists = true;
    }

    // تنفيذ عمليات الإضافة والتعديل والحذف
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token'] ?? ''
        )) {
            throw new Exception(
                'انتهت صلاحية الطلب. يرجى تحديث الصفحة.'
            );
        }

        $action = $_POST['action'] ?? '';

        $courseId = (int)($_POST['course_id'] ?? 0);

        // التأكد من أن المقرر مسند إلى عضو هيئة التدريس
        $checkCourse = $pdo->prepare("
            SELECT id
            FROM course_instructors
            WHERE course_id = ?
              AND faculty_user_id = ?
            LIMIT 1
        ");

        $checkCourse->execute([
            $courseId,
            $facultyId
        ]);

        if (!$checkCourse->fetch()) {
            throw new Exception(
                'المقرر المحدد غير مسند إلى حسابك.'
            );
        }

        // حذف واجب
        if ($action === 'delete') {

            $assignmentId = (int)(
                $_POST['assignment_id'] ?? 0
            );

            $delete = $pdo->prepare("
                DELETE FROM assignments
                WHERE id = ?
                  AND course_id = ?
            ");

            $delete->execute([
                $assignmentId,
                $courseId
            ]);

            if ($delete->rowCount() > 0) {
                $success = 'تم حذف الواجب بنجاح.';
            } else {
                throw new Exception(
                    'لم يتم العثور على الواجب المطلوب حذفه.'
                );
            }
        }

        // إضافة أو تعديل واجب
        elseif ($action === 'save') {

            $assignmentId = (int)(
                $_POST['assignment_id'] ?? 0
            );

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $dueDate = trim($_POST['due_date'] ?? '');
            $maxScore = $_POST['max_score'] ?? '';

            if ($title === '') {
                throw new Exception(
                    'يرجى إدخال عنوان الواجب.'
                );
            }

            if (mb_strlen($title) > 200) {
                throw new Exception(
                    'عنوان الواجب يجب ألا يتجاوز 200 حرف.'
                );
            }

            if (!is_numeric($maxScore) || (float)$maxScore <= 0) {
                throw new Exception(
                    'الدرجة الكاملة يجب أن تكون أكبر من صفر.'
                );
            }

            $maxScoreValue = (float)$maxScore;

            // التحقق من تاريخ التسليم
            $dueDateValue = null;

            if ($dueDate !== '') {

                $dateObject = DateTime::createFromFormat(
                    'Y-m-d\TH:i',
                    $dueDate
                );

                if (
                    !$dateObject ||
                    $dateObject->format('Y-m-d\TH:i') !== $dueDate
                ) {
                    throw new Exception(
                        'تاريخ التسليم غير صحيح.'
                    );
                }

                $dueDateValue = $dateObject->format(
                    'Y-m-d H:i:s'
                );
            }

            if ($assignmentId > 0) {

                // التحقق من أن الواجب تابع للمقرر المحدد
                $checkAssignment = $pdo->prepare("
                    SELECT id
                    FROM assignments
                    WHERE id = ?
                      AND course_id = ?
                    LIMIT 1
                ");

                $checkAssignment->execute([
                    $assignmentId,
                    $courseId
                ]);

                if (!$checkAssignment->fetch()) {
                    throw new Exception(
                        'الواجب المطلوب تعديله غير موجود.'
                    );
                }

                // تعديل الواجب
                $update = $pdo->prepare("
                    UPDATE assignments
                    SET title = ?,
                        description = ?,
                        due_date = ?,
                        max_score = ?
                    WHERE id = ?
                      AND course_id = ?
                ");

                $update->execute([
                    $title,
                    $description !== '' ? $description : null,
                    $dueDateValue,
                    $maxScoreValue,
                    $assignmentId,
                    $courseId
                ]);

                $success = 'تم تعديل الواجب بنجاح.';

            } else {

                // إضافة واجب جديد
                $insert = $pdo->prepare("
                    INSERT INTO assignments
                    (
                        course_id,
                        title,
                        description,
                        due_date,
                        max_score
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $courseId,
                    $title,
                    $description !== '' ? $description : null,
                    $dueDateValue,
                    $maxScoreValue
                ]);

                $success = 'تمت إضافة الواجب بنجاح.';
            }

            $editId = 0;
        }

        else {
            throw new Exception(
                'العملية المطلوبة غير صحيحة.'
            );
        }

        $selectedCourse = $courseId;
    }

    // جلب بيانات الواجب المطلوب تعديله
    $editAssignment = null;

    if ($editId > 0 && $selectedCourse > 0) {

        $stmt = $pdo->prepare("
            SELECT
                id,
                course_id,
                title,
                description,
                due_date,
                max_score
            FROM assignments
            WHERE id = ?
              AND course_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $editId,
            $selectedCourse
        ]);

        $editAssignment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editAssignment) {
            $editId = 0;
        }
    }

    // جلب واجبات المقرر المحدد
    if ($selectedCourse > 0) {

        $stmt = $pdo->prepare("
            SELECT
                id,
                course_id,
                title,
                description,
                due_date,
                max_score,
                created_at
            FROM assignments
            WHERE course_id = ?
            ORDER BY created_at DESC, id DESC
        ");

        $stmt->execute([$selectedCourse]);

        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();

} catch (Exception $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $e->getMessage();
}

// تحويل تاريخ قاعدة البيانات إلى صيغة حقل التاريخ
$editDueDate = '';

if (!empty($editAssignment['due_date'])) {
    $editDueDate = date(
        'Y-m-d\TH:i',
        strtotime($editAssignment['due_date'])
    );
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>الواجبات والتكليفات | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #f3f6fb;
            color: #263445;
        }

        header {
            background: #172b4d;
            color: white;
            padding: 22px 7%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header h1 {
            margin: 0;
            font-size: 22px;
        }

        header p {
            margin: 8px 0 0;
            color: #d5deeb;
            font-size: 13px;
        }

        .back-link {
            color: white;
            text-decoration: none;
            background: #29466f;
            padding: 10px 17px;
            border-radius: 8px;
            font-size: 13px;
        }

        .container {
            width: 86%;
            max-width: 1200px;
            margin: 35px auto;
        }

        .page-title {
            margin-bottom: 22px;
        }

        .page-title h2 {
            margin: 0 0 9px;
            color: #172b4d;
            font-size: 23px;
        }

        .page-title p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .card h3 {
            margin: 0 0 18px;
            color: #172b4d;
            font-size: 17px;
        }

        .filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: end;
        }

        .field {
            flex: 1;
            min-width: 190px;
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 13px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce3ed;
            border-radius: 8px;
            background: white;
            font-family: inherit;
            font-size: 13px;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        button {
            border: none;
            border-radius: 8px;
            padding: 12px 22px;
            background: #245a9b;
            color: white;
            font-family: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        button:hover {
            background: #194779;
        }

        .save-button {
            background: #16845b;
        }

        .save-button:hover {
            background: #116b49;
        }

        .cancel-link {
            display: inline-block;
            color: #64748b;
            text-decoration: none;
            margin-right: 12px;
            font-size: 13px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 750px;
            border-collapse: collapse;
        }

        th {
            background: #eaf0f8;
            color: #172b4d;
            padding: 15px 10px;
            font-size: 13px;
            text-align: center;
        }

        td {
            padding: 13px 10px;
            text-align: center;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .message {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .success {
            background: #e7f7ef;
            color: #16734b;
        }

        .error {
            background: #fff0ef;
            color: #b42318;
        }

        .empty-state {
            text-align: center;
            padding: 35px 20px;
            color: #718096;
            background: white;
            border-radius: 12px;
            line-height: 1.8;
        }

        .action-link {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            margin: 3px;
        }

        .edit-link {
            background: #eaf2ff;
            color: #245a9b;
        }

        .delete-button {
            background: #fff0ef;
            color: #b42318;
            padding: 7px 12px;
            font-size: 12px;
        }

        .delete-button:hover {
            background: #fbd8d5;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #8793a4;
            font-size: 12px;
        }

        @media (max-width: 600px) {
            header {
                padding: 20px;
            }

            .container {
                width: 94%;
                margin: 25px auto;
            }

            header h1 {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>

<header>
    <div>
        <h1>نظام إدارة الحرم الجامعي الذكي</h1>
        <p>بوابة عضو هيئة التدريس</p>
    </div>

    <a class="back-link" href="dashboard.php">
        العودة إلى لوحة التحكم
    </a>
</header>

<main class="container">

    <div class="page-title">
        <h2>الواجبات والتكليفات</h2>
        <p>إضافة الواجبات الأكاديمية وتحديد مواعيد التسليم والدرجات ومتابعة الواجبات السابقة.</p>
    </div>

    <?php if ($success): ?>
        <div class="message success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($courses)): ?>

        <div class="empty-state">
            <h3>لا توجد مقررات مسندة إليك</h3>
            <p>لا يمكن إدارة الواجبات قبل إسناد مقرر إلى حسابك.</p>
        </div>

    <?php else: ?>

        <section class="card">
            <h3>اختيار المقرر الدراسي</h3>

            <form method="GET" class="filters">

                <div class="field">
                    <label for="course_id">المقرر الدراسي</label>

                    <select
                        name="course_id"
                        id="course_id"
                        required
                    >
                        <?php foreach ($courses as $course): ?>
                            <option
                                value="<?= (int)$course['id'] ?>"
                                <?= $selectedCourse === (int)$course['id'] ? 'selected' : '' ?>
                            >
                                <?= e($course['course_code']) ?>
                                - <?= e($course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">عرض الواجبات</button>

            </form>
        </section>

        <section class="card">

            <h3>
                <?= $editAssignment ? 'تعديل الواجب' : 'إضافة واجب جديد' ?>
            </h3>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="course_id"
                    value="<?= (int)$selectedCourse ?>"
                >

                <input
                    type="hidden"
                    name="assignment_id"
                    value="<?= (int)($editAssignment['id'] ?? 0) ?>"
                >

                <div class="field">
                    <label for="title">عنوان الواجب</label>

                    <input
                        type="text"
                        name="title"
                        id="title"
                        maxlength="200"
                        value="<?= e($editAssignment['title'] ?? '') ?>"
                        placeholder="أدخل عنوان الواجب"
                        required
                    >
                </div>

                <div class="field">
                    <label for="description">وصف الواجب</label>

                    <textarea
                        name="description"
                        id="description"
                        placeholder="أدخل تفاصيل وتعليمات الواجب"
                    ><?= e($editAssignment['description'] ?? '') ?></textarea>
                </div>

                <div class="filters">

                    <div class="field">
                        <label for="due_date">موعد التسليم</label>

                        <input
                            type="datetime-local"
                            name="due_date"
                            id="due_date"
                            value="<?= e($editDueDate) ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="max_score">الدرجة الكاملة</label>

                        <input
                            type="number"
                            name="max_score"
                            id="max_score"
                            min="0.01"
                            step="0.01"
                            value="<?= e($editAssignment['max_score'] ?? '20') ?>"
                            required
                        >
                    </div>

                </div>

                <button type="submit" class="save-button">
                    <?= $editAssignment ? 'حفظ التعديلات' : 'إضافة الواجب' ?>
                </button>

                <?php if ($editAssignment): ?>
                    <a
                        class="cancel-link"
                        href="faculty_assignments.php?course_id=<?= (int)$selectedCourse ?>"
                    >
                        إلغاء التعديل
                    </a>
                <?php endif; ?>

            </form>

        </section>

        <section class="card">

            <h3>الواجبات السابقة</h3>

            <?php if (empty($assignments)): ?>

                <div class="empty-state">
                    <h3>لا توجد واجبات لهذا المقرر</h3>
                    <p>يمكنك إضافة أول واجب باستخدام النموذج أعلاه.</p>
                </div>

            <?php else: ?>

                <div class="table-container">

                    <table>
                        <thead>
                            <tr>
                                <th>عنوان الواجب</th>
                                <th>الوصف</th>
                                <th>موعد التسليم</th>
                                <th>الدرجة الكاملة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($assignments as $assignment): ?>

                                <tr>

                                    <td>
                                        <?= e($assignment['title']) ?>
                                    </td>

                                    <td>
                                        <?= e($assignment['description'] ?: 'لا يوجد وصف') ?>
                                    </td>

                                    <td>
                                        <?php if ($assignment['due_date']): ?>
                                            <?= e(date(
                                                'Y-m-d H:i',
                                                strtotime($assignment['due_date'])
                                            )) ?>
                                        <?php else: ?>
                                            غير محدد
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= e($assignment['max_score']) ?>
                                    </td>

                                    <td>

                                        <a
                                            class="action-link edit-link"
                                            href="faculty_assignments.php?course_id=<?= (int)$selectedCourse ?>&edit=<?= (int)$assignment['id'] ?>"
                                        >
                                            تعديل
                                        </a>

                                        <form
                                            method="POST"
                                            style="display:inline;"
                                            onsubmit="return confirm('هل أنت متأكد من حذف هذا الواجب؟');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['csrf_token']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="course_id"
                                                value="<?= (int)$selectedCourse ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="assignment_id"
                                                value="<?= (int)$assignment['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="delete-button"
                                            >
                                                حذف
                                            </button>

                                        </form>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>
                    </table>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

<footer>
    S-UCMS - Smart University Campus Management System
</footer>

</body>
</html>