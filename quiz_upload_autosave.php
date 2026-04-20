<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_prof();

header('Content-Type: application/json');

$quizId = (int)($_POST['quiz_id'] ?? 0);

if (!$quizId) {
    echo json_encode(['status'=>'no_quiz']);
    exit;
}

$uploadDir = dirname(__DIR__) . '/uploads/quiz';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

$allowed = ['pdf','doc','docx','jpg','png','mp4','mp3','ppt','pptx','xls','xlsx'];

try {

    // ✅ INSERT
    $stmtInsert = $con->prepare("
        INSERT INTO quiz_attachment
        (quiz_id,file_path,original_name,mime_type,file_size)
        VALUES (?,?,?,?,?)
    ");

    // ✅ CHECK EXIST
    $stmtCheck = $con->prepare("
        SELECT id FROM quiz_attachment
        WHERE quiz_id=? AND original_name=?
        LIMIT 1
    ");

    foreach ($_FILES['attachments']['name'] as $i => $name) {

        if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;

        $name = trim($name);
        if ($name === '') continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) continue;

        // 🔍 vérifier si existe
        $stmtCheck->bind_param("is", $quizId, $name);
        $stmtCheck->execute();

        if ($stmtCheck->get_result()->fetch_assoc()) {
            continue; // déjà existant
        }

        // 📁 upload
        $new = uniqid('q_'.$quizId.'_').'.'.$ext;
        $path = $uploadDir.'/'.$new;

        if (!move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $path)) {
            continue;
        }

        $web = '/uploads/quiz/'.$new;

        $stmtInsert->bind_param(
            "isssi",
            $quizId,
            $web,
            $name,
            $_FILES['attachments']['type'][$i],
            $_FILES['attachments']['size'][$i]
        );

        $stmtInsert->execute();
    }

    echo json_encode(['status'=>'ok']);

} catch (Throwable $e) {

    echo json_encode([
        'status'=>'error',
        'message'=>$e->getMessage()
    ]);
}