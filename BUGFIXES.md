# Найденные и исправленные баги

## 1. **Проблема с кодировкой Unicode в JSON ответах** (КРИТИЧНО)
**Файлы:** `api/letters.php`, `api/members.php`, `api/commissions.php`, `api/auth.php`, `api/upload_photo.php`

**Проблема:** Многие `json_encode()` вызовы не использовали флаги `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, что приводило к неправильной кодировке кириллицы в JSON ответах.

**Исправление:** 
- Добавлены флаги `$JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` в начало каждого файла
- Все `json_encode()` вызовы обновлены на использование `$JSON_FLAGS`

---

## 2. **Жесткий регион_id=1 в auth.php** (СРЕДНЯЯ)
**Файл:** `api/auth.php`

**Проблема:** При проверке аутентификации регион всегда устанавливался на 1, игнорируя реальный регион пользователя из БД.

**Исправление:**
```php
// Было:
$stmt4 = $db->prepare("SELECT * FROM regions WHERE id = 1");
$stmt4->execute();
$region = $stmt4->fetch();
$user['region_id'] = 1;

// Стало:
$stmt2 = $db->prepare("SELECT * FROM regions WHERE id = ?");
$stmt2->execute([$user['region_id'] ?? 1]);
$region = $stmt2->fetch();
```

---

## 3. **Отсутствие очистки сессии при истечении** (СРЕДНЯЯ)
**Файл:** `api/auth.php`

**Проблема:** Когда сессия истекала, она не удалялась из памяти, что могло привести к проблемам безопасности.

**Исправление:** Добавлена очистка сессии при обнаружении истекшей сессии:
```php
} else {
    $_SESSION = [];
    session_destroy();
    http_response_code(401);
    echo json_encode(['authenticated' => false], $JSON_FLAGS);
}
```

---

## 4. **Отсутствие удаления старых фото** (НИЗКАЯ)
**Файл:** `api/upload_photo.php`

**Проблема:** При загрузке нового фото старое не удалялось, что приводило к накоплению файлов на диске.

**Исправление:** Добавлена проверка и удаление старого фото перед загрузкой нового:
```php
$stmt = $db->prepare("SELECT photo_path FROM os_members WHERE id = ?");
$stmt->execute([$member_id]);
$oldPhoto = $stmt->fetchColumn();
if ($oldPhoto && file_exists($oldPhoto)) {
    @unlink($oldPhoto);
}
```

---

## 5. **Отсутствие header Content-Type в некоторых файлах** (НИЗКАЯ)
**Файлы:** `api/commissions.php`, `api/auth.php`, `api/upload_photo.php`

**Проблема:** Не все API файлы устанавливали правильный Content-Type header для JSON ответов.

**Исправление:** Добавлены `header('Content-Type: application/json; charset=utf-8');` в начало каждого файла.

---

## Резюме исправлений:

✅ Исправлена кодировка Unicode во всех JSON ответах
✅ Исправлена работа с регионами пользователей
✅ Добавлена очистка истекших сессий
✅ Добавлено удаление старых фото при загрузке новых
✅ Добавлены правильные Content-Type headers

Все файлы обновлены и готовы к использованию.
