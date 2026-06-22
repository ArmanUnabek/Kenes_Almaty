<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Repositories/MemberRepository.php';

use App\ApiController;
use App\Repositories\MemberRepository;

class PhotoUploadController extends ApiController
{
    private MemberRepository $memberRepo;

    public function __construct()
    {
        parent::__construct();
        $this->memberRepo = new MemberRepository($this->db);
    }

    public function handle(): void
    {
        try {
            $this->requireAuth();
            $this->requireWriteAccess();
            $this->requireCsrf();

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->error('Только POST метод разрешен', 405);
            }

            if (!file_exists(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $this->error('Ошибка загрузки файла', 400);
            }

            $file = $_FILES['photo'];
            $member_id = $_POST['member_id'] ?? null;

            if (!$member_id || !ctype_digit((string)$member_id)) {
                $this->error('ID члена ОС не указан', 400);
            }
            $member_id = (int)$member_id;

            $member = $this->memberRepo->getById($member_id, $this->getCurrentRegionId());
            if (!$member) {
                $this->error('Член ОС не найден', 404);
            }

            $this->validateFile($file);

            $extension = $this->getFileExtension($file['tmp_name']);
            $filename = 'member_' . $member_id . '_' . time() . '.' . $extension;
            $absPath = UPLOAD_DIR . $filename;              // абсолютный, для записи/удаления
            $dbPath  = 'uploads/photos/' . $filename;      // относительный, хранится в БД

            $oldPhoto = $this->memberRepo->getPhotoPath($member_id);
            if ($oldPhoto) {
                $oldAbsPath = APP_ROOT . '/' . ltrim($oldPhoto, '/');
                if (file_exists($oldAbsPath)) {
                    @unlink($oldAbsPath);
                }
            }

            if (!move_uploaded_file($file['tmp_name'], $absPath)) {
                $this->error('Ошибка сохранения файла', 500);
            }

            $this->memberRepo->updatePhotoPath($member_id, $dbPath);
            $this->logAction('os_members', $member_id, 'UPDATE_PHOTO', null, ['photo_path' => $dbPath]);

            $this->success([
                'photo_url' => MemberRepository::photoApiUrl($member_id, (string)time()),
            ], 'Фото успешно загружено', 201);
        } catch (\Exception $e) {
            $this->handleException($e, 'PhotoUploadController');
        }
    }

    private function validateFile(array $file): void
    {
        $allowedMimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if (!$detectedMime || !isset($allowedMimeMap[$detectedMime])) {
            $this->error('Недопустимый тип файла. Разрешены только JPG и PNG', 400);
        }

        if ($file['size'] > MAX_FILE_SIZE) {
            $this->error('Файл слишком большой. Максимум 5 МБ', 400);
        }
    }

    private function getFileExtension(string $filePath): string
    {
        $allowedMimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return $allowedMimeMap[$mime] ?? 'jpg';
    }
}

$controller = new PhotoUploadController();
$controller->handle();
