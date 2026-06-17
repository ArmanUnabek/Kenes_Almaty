# KazLLM — перевод пользовательского контента

Модель [issai/LLama-3.1-KazLLM-1.0-70B-GGUF4](https://huggingface.co/issai/LLama-3.1-KazLLM-1.0-70B-GGUF4) — **не API перевода**, а казахоязычная LLM (~42 ГБ VRAM в Q4). Её запускают на **отдельном GPU-сервере**, а журнал ОС обращается к ней по HTTP.

## Что переводит

| Тип | Как |
|-----|-----|
| Кнопки, меню, подписи | Статический `i18n.js` (уже сделано) |
| Должность, организация члена ОС | KazLLM + кнопка «Аудару» в форме |
| Названия комиссий, темы писем | API `POST /api/translate.php` |

## Архитектура

```
Браузер → PHP (журнал) → Ollama / llama.cpp → KazLLM 70B GGUF
                ↓
         translation_cache (MySQL)
```

## 1. Скачать модель

На GPU-сервере (Linux, NVIDIA ≥ 48 ГБ VRAM рекомендуется):

```bash
pip install huggingface-hub
huggingface-cli login   # нужно принять условия модели на HF
huggingface-cli download issai/LLama-3.1-KazLLM-1.0-70B-GGUF4 \
  --include "*.gguf" --local-dir ./kazllm-70b
```

## 2. Запуск через Ollama (рекомендуется)

```bash
# Установить Ollama: https://ollama.com
cat > Modelfile <<'EOF'
FROM ./kazllm-70b/ИМЯ_ФАЙЛА.gguf
PARAMETER temperature 0.1
PARAMETER num_ctx 8192
SYSTEM "Сен кәсіби аудармашысың. Тек аударма мәтінін қайтар."
EOF

ollama create kazllm -f Modelfile
ollama serve   # порт 11434
```

Проверка:

```bash
curl http://127.0.0.1:11434/api/chat -d '{
  "model": "kazllm",
  "messages": [{"role":"user","content":"Переведи на казахский: Член Общественного совета"}],
  "stream": false
}'
```

## 3. Альтернатива: llama.cpp

```bash
./llama-server -m kazllm-70b/model.gguf --port 8080 --host 0.0.0.0
```

В `.env` укажите `KAZLLM_PROVIDER=openai` и `KAZLLM_API_URL=http://gpu-server:8080`.

## 4. Настройка журнала ОС

В `.env` на веб-сервере:

```env
KAZLLM_ENABLED=true
KAZLLM_API_URL=http://10.0.0.5:11434
KAZLLM_MODEL=kazllm
KAZLLM_PROVIDER=ollama
KAZLLM_TIMEOUT=120
```

Проверка статуса (модератор/админ):

```
GET /api/translate.php?action=status
```

Перевод:

```
POST /api/translate.php
Content-Type: application/json

{"text": "Председатель комиссии", "source": "ru", "target": "kk"}
```

## 5. UI — член ОС

В форме члена ОС появляется кнопка **«Аудару (KK)»** рядом с полем «Должность». Результат сохраняется в `position_kz` и показывается при переключении интерфейса на казахский.

## Лицензия

Модель: **CC BY-NC 4.0** (некоммерческое использование) + условия Meta Llama 3.1. Для коммерческого SaaS свяжитесь с ISSAI: issai@nu.edu.kz.

## Ограничения

- 70B медленная на CPU; нужен GPU
- Перевод кэшируется в `translation_cache`
- Лимит: 30 запросов/час на пользователя
- Для лёгкого сервера рассмотрите меньшие модели из [коллекции ISSAI](https://huggingface.co/issai)
