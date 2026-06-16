# Руководство по тестированию API

## Инструменты для тестирования

### 1. cURL (командная строка)

```bash
# GET запрос
curl -X GET http://localhost/api/members.php

# POST запрос
curl -X POST http://localhost/api/members.php \
  -H "Content-Type: application/json" \
  -d '{"full_name": "Иван Иванов"}'
```

### 2. Postman

Импортируйте коллекцию из `api/docs/postman_collection.json`

### 3. Thunder Client (VS Code)

Установите расширение и используйте встроенный клиент

## Примеры тестирования

### Аутентификация

```bash
# Получить CSRF токен
curl -X GET http://localhost/api/auth.php?action=csrf

# Вход
curl -X POST http://localhost/api/auth.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=login&username=admin&password=password&_csrf_token=YOUR_TOKEN"

# Проверка сессии
curl -X GET http://localhost/api/auth.php \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID"

# Выход
curl -X POST http://localhost/api/auth.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=logout"
```

### Члены ОС

```bash
# Получить всех членов
curl -X GET http://localhost/api/members.php

# Получить члена по ID
curl -X GET http://localhost/api/members.php?id=1

# Получить членов комиссии
curl -X GET http://localhost/api/members.php?commission_id=1

# Пагинация
curl -X GET "http://localhost/api/members.php?page=1&limit=20"

# Создать члена
curl -X POST http://localhost/api/members.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "full_name": "Иван Иванов",
    "email": "ivan@example.com",
    "phone": "+7 (999) 123-45-67",
    "position": "Директор",
    "organization": "ООО Компания",
    "commission_id": 1,
    "status": "active"
  }'

# Обновить члена
curl -X PUT http://localhost/api/members.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "id": 1,
    "full_name": "Иван Иванович Иванов",
    "email": "ivan.new@example.com",
    "status": "active"
  }'

# Удалить члена
curl -X DELETE "http://localhost/api/members.php?id=1" \
  -H "X-CSRF-Token: YOUR_TOKEN"
```

### Комиссии

```bash
# Получить все комиссии
curl -X GET http://localhost/api/commissions.php

# Получить комиссию по ID
curl -X GET http://localhost/api/commissions.php?id=1

# Создать комиссию
curl -X POST http://localhost/api/commissions.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "name": "Комиссия по образованию",
    "description": "Занимается вопросами образования",
    "color": "#0d6efd",
    "sort_order": 1
  }'

# Обновить комиссию
curl -X PUT http://localhost/api/commissions.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "id": 1,
    "name": "Комиссия по образованию и науке",
    "color": "#0d6efd"
  }'

# Удалить комиссию
curl -X DELETE "http://localhost/api/commissions.php?id=1" \
  -H "X-CSRF-Token: YOUR_TOKEN"
```

### Письма

```bash
# Получить входящие письма
curl -X GET http://localhost/api/letters.php?type=incoming

# Получить исходящие письма
curl -X GET http://localhost/api/letters.php?type=outgoing

# Получить письмо по ID
curl -X GET http://localhost/api/letters.php?type=incoming&id=1

# Пагинация
curl -X GET "http://localhost/api/letters.php?type=incoming&page=1&limit=50"

# Создать входящее письмо
curl -X POST http://localhost/api/letters.php?type=incoming \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "date": "2024-01-15",
    "organization": "Министерство",
    "kk_number": "КК-2024-001",
    "category": "KK",
    "subject": "Письмо о сотрудничестве",
    "note": "Важное письмо",
    "members": [1, 2, 3],
    "recipients": ["Иван Иванов", "Петр Петров"]
  }'

# Создать исходящее письмо
curl -X POST http://localhost/api/letters.php?type=outgoing \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "date": "2024-01-15",
    "organization": "Министерство",
    "outgoing_number": "ИС-2024-001",
    "outgoing_type": "gov",
    "subject": "Ответ на письмо",
    "incoming_ref_id": 1,
    "members": [1, 2],
    "recipients": ["Иван Иванов"]
  }'

# Обновить письмо
curl -X PUT http://localhost/api/letters.php?type=incoming \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "id": 1,
    "date": "2024-01-16",
    "subject": "Обновленное письмо",
    "members": [1, 2, 3, 4]
  }'

# Удалить письмо
curl -X DELETE "http://localhost/api/letters.php?type=incoming&id=1" \
  -H "X-CSRF-Token: YOUR_TOKEN"
```

### Загрузка фото

```bash
# Загрузить фото члена ОС
curl -X POST http://localhost/api/upload_photo.php \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -F "photo=@/path/to/photo.jpg" \
  -F "member_id=1"
```

## Сценарии тестирования

### Сценарий 1: Полный цикл работы с членом ОС

```bash
# 1. Получить CSRF токен
TOKEN=$(curl -s http://localhost/api/auth.php?action=csrf | jq -r '.csrf_token')

# 2. Создать члена
MEMBER_ID=$(curl -s -X POST http://localhost/api/members.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  -d '{"full_name": "Тестовый Пользователь"}' | jq -r '.data.id')

# 3. Загрузить фото
curl -X POST http://localhost/api/upload_photo.php \
  -H "X-CSRF-Token: $TOKEN" \
  -F "photo=@test.jpg" \
  -F "member_id=$MEMBER_ID"

# 4. Получить члена
curl -X GET "http://localhost/api/members.php?id=$MEMBER_ID"

# 5. Обновить члена
curl -X PUT http://localhost/api/members.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  -d "{\"id\": $MEMBER_ID, \"full_name\": \"Обновленное имя\"}"

# 6. Удалить члена
curl -X DELETE "http://localhost/api/members.php?id=$MEMBER_ID" \
  -H "X-CSRF-Token: $TOKEN"
```

### Сценарий 2: Работа с письмами

```bash
# 1. Получить CSRF токен
TOKEN=$(curl -s http://localhost/api/auth.php?action=csrf | jq -r '.csrf_token')

# 2. Создать входящее письмо
INCOMING_ID=$(curl -s -X POST http://localhost/api/letters.php?type=incoming \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  -d '{
    "date": "2024-01-15",
    "organization": "Тестовая организация",
    "kk_number": "КК-2024-001",
    "category": "KK",
    "subject": "Тестовое письмо"
  }' | jq -r '.data.id')

# 3. Создать исходящее письмо
OUTGOING_ID=$(curl -s -X POST http://localhost/api/letters.php?type=outgoing \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  -d "{
    \"date\": \"2024-01-16\",
    \"organization\": \"Тестовая организация\",
    \"outgoing_number\": \"ИС-2024-001\",
    \"incoming_ref_id\": $INCOMING_ID
  }" | jq -r '.data.id')

# 4. Получить входящее письмо
curl -X GET "http://localhost/api/letters.php?type=incoming&id=$INCOMING_ID"

# 5. Получить исходящее письмо
curl -X GET "http://localhost/api/letters.php?type=outgoing&id=$OUTGOING_ID"
```

## Проверка ошибок

### Тест 1: Отсутствие CSRF токена

```bash
curl -X POST http://localhost/api/members.php \
  -H "Content-Type: application/json" \
  -d '{"full_name": "Иван"}'

# Ожидаемый ответ: 403 Forbidden
```

### Тест 2: Некорректные данные

```bash
TOKEN=$(curl -s http://localhost/api/auth.php?action=csrf | jq -r '.csrf_token')

curl -X POST http://localhost/api/members.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  -d '{"full_name": "И"}'

# Ожидаемый ответ: 400 Bad Request
```

### Тест 3: Несуществующий ресурс

```bash
curl -X GET http://localhost/api/members.php?id=99999

# Ожидаемый ответ: 404 Not Found
```

### Тест 4: Недостаточно прав

```bash
# Попытка удалить с правами moderator
curl -X DELETE "http://localhost/api/members.php?id=1" \
  -H "X-CSRF-Token: $TOKEN"

# Ожидаемый ответ: 403 Forbidden
```

## Автоматизированное тестирование

### Пример с использованием PHPUnit

```php
<?php
use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    private string $baseUrl = 'http://localhost';

    public function testGetMembers()
    {
        $response = file_get_contents($this->baseUrl . '/api/members.php');
        $data = json_decode($response, true);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
    }

    public function testCreateMember()
    {
        $token = $this->getCsrfToken();
        
        $data = [
            'full_name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'X-CSRF-Token: ' . $token
                ],
                'content' => json_encode($data)
            ]
        ]);

        $response = file_get_contents($this->baseUrl . '/api/members.php', false, $context);
        $result = json_decode($response, true);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
    }

    private function getCsrfToken(): string
    {
        $response = file_get_contents($this->baseUrl . '/api/auth.php?action=csrf');
        $data = json_decode($response, true);
        return $data['csrf_token'];
    }
}
```

## Мониторинг производительности

```bash
# Проверить время ответа
time curl -X GET http://localhost/api/members.php

# Проверить использование памяти
curl -X GET http://localhost/api/members.php -w "\nTime: %{time_total}s\n"
```

## Логирование запросов

Все запросы логируются в `logs/app.log`:

```bash
tail -f logs/app.log | grep "POST\|PUT\|DELETE"
```
