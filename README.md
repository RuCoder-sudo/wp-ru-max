# 📡 Интеграция WordPress с мессенджером MAX

[Русская версия](#-wp-ru-max--интеграция-wordpress-с-мессенджером-max) | [English version](#-wp-ru-max--wordpress-integration-with-max-messenger)

<p align="center">
  <img src="assets/banneruss.png" alt="WP Ru-max — отправка постов и уведомлений в MAX">
</p>

## 🇷🇺 WP Ru-max — интеграция WordPress с мессенджером MAX

**WP Ru-max** — это полнофункциональный плагин для WordPress, который подключает ваш сайт к российскому мессенджеру **MAX (max.ru)**. Автоматическая публикация записей, личные уведомления с любых форм и красивый чат-виджет.

---

### 🚀 Основные возможности

#### 📢 Автопубликация
- Новая или обновлённая запись / страница → автоматически отправляется в **канал MAX**.
- Поддержка миниатюры (изображение прикрепляется к сообщению).
- Возможность отключить ссылку «Читать полностью».

#### 🔔 Личные уведомления
- Перехват всех email-уведомлений WordPress.
- Поддержка **WooCommerce** (новые заказы), **Contact Form 7**, **Elementor Forms**, **Gravity Forms**, **Ninja Forms** и любых других форм, использующих `wp_mail()`.
- Каждое письмо дублируется в личный чат с ботом MAX.

#### 💬 Чат-виджет
- Плавающая кнопка MAX на сайте.
- Анимация «печатания» для приветственного сообщения.
- Полная кастомизация внешнего вида (через настройки плагина).

#### 📋 История и логи
- Полная таблица всех событий (отправки, ошибки, тесты).
- Фильтрация по типу, дате, статусу.
- Просмотр подробного JSON-лога каждого запроса.

#### 🧪 Тестирование
- Встроенная проверка подключения к боту MAX.
- Отправка тестового сообщения в выбранный канал / чат.

---

### ⚙️ Совместимость

| Компонент            | Поддержка |
|----------------------|-----------|
| WordPress            | 5.8 – 6.7 |
| PHP                  | 7.4+      |
| WooCommerce          | ✔️ (заказы) |
| Contact Form 7       | ✔️         |
| Elementor Forms      | ✔️         |
| Gravity Forms        | ✔️         |
| Ninja Forms          | ✔️         |
| Любые формы через `wp_mail` | ✔️ |

---

### 📥 Установка

1. Скачайте архив плагина или клонируйте репозиторий:
   ```bash
   git clone https://github.com/RuCoder-sudo/wp-ru-max.git
Загрузите папку wp-ru-max в /wp-content/plugins/.

Активируйте плагин через меню Плагины в WordPress.

Перейдите в раздел Ru-max → Главная.

Введите токен бота MAX (получается в партнёрском разделе MAX → Чат-боты → Интеграция → Получить токен).

Настройте автопубликацию, уведомления и виджет.

💡 Совет: После активации плагина проверьте соединение кнопкой «Проверить подключение» на вкладке «Главная».

❓ Часто задаваемые вопросы
Где взять токен бота MAX?
На платформе MAX для партнёров: https://max.ru/partner → раздел «Чат-боты» → «Интеграция» → «Получить токен».

Как узнать ID канала?
Для публичного канала используйте его никнейм с @ (например, @news_channel).

Для группы — числовой ID (можно получить через бота @get_id_bot в самом MAX).

Работает ли плагин с WooCommerce?
Да. Плагин автоматически перехватывает все email-уведомления WooCommerce (новый заказ, смена статуса и т.д.) и отправляет их в MAX.

Можно ли отключить ссылку «Читать полностью»?
Да. В настройках автопубликации есть чекбокс «Добавлять ссылку "Читать полностью"».

Поддерживает ли плагин Elementor Pro?
Да, включая виджеты форм Elementor Pro.

🧪 Статус проекта
✅ Стабильная работа на WordPress 6.7

✅ Проверено с PHP 8.0 – 8.3

✅ Все функции протестированы

✅ Готов к использованию в продакшене

📌 Лицензия
GPL v2 or later
Полный текст лицензии: https://www.gnu.org/licenses/gpl-2.0.html

👨‍💻 Автор
Sergey Soloshenko (RuCoder)
🛠 WordPress / Full Stack разработчик
📬 support@рукодер.рф
📲 Telegram: @RussCoder
🌐 https://рукодер.рф

🇺🇸 WP Ru-max — WordPress Integration with MAX Messenger
WP Ru-max is a complete WordPress plugin that connects your site to the Russian messenger MAX (max.ru). Auto-publish posts, receive personal notifications from any forms, and add a chat widget.

🚀 Features
📢 Auto-publishing
New/updated posts or pages → automatically sent to a MAX channel.

Featured image support (attached to the message).

Option to disable the “Read more” link.

🔔 Personal notifications
Intercepts all WordPress email notifications.

Supports WooCommerce (new orders), Contact Form 7, Elementor Forms, Gravity Forms, Ninja Forms, and any form using wp_mail().

Each email is duplicated as a private message to your MAX bot.

💬 Chat widget
Floating MAX button on your website.

“Typing” animation for the welcome message.

Fully customizable appearance.

📋 Event history & logs
Full table of all events (sent, errors, tests).

Filter by type, date, status.

View detailed JSON log for each request.

🧪 Testing tools
Built-in connection test to your MAX bot.

Send a test message to any channel / chat.

⚙️ Compatibility
Component	Support
WordPress	5.8 – 6.7
PHP	7.4+
WooCommerce	✔️ (orders)
Contact Form 7	✔️
Elementor Forms	✔️
Gravity Forms	✔️
Ninja Forms	✔️
Any form using wp_mail	✔️
📥 Installation
Download the plugin archive or clone the repository:

bash
git clone https://github.com/RuCoder-sudo/wp-ru-max.git
Upload the wp-ru-max folder to /wp-content/plugins/.

Activate the plugin via Plugins menu in WordPress.

Go to Ru-max → General tab.

Enter your MAX bot token (get it from MAX partner area → Chat bots → Integration → Get token).

Configure auto‑publishing, notifications, and the widget.

💡 Tip: After activation, use the “Test connection” button on the General tab.

❓ FAQ
Where can I get the MAX bot token?
In the MAX partner area: https://max.ru/partner → “Chat bots” → “Integration” → “Get token”.

How to find my channel ID?
For a public channel – use its nickname with @ (e.g., @news_channel).

For a group – numeric ID (you can get it via @get_id_bot inside MAX).

Does it work with WooCommerce?
Yes. The plugin automatically intercepts all WooCommerce email notifications (new order, status change, etc.) and forwards them to MAX.

Can I remove the “Read more” link?
Yes – there is a checkbox in the auto‑publishing settings.

Does it support Elementor Pro?
Yes, including Elementor Pro Form widgets.

🧪 Project Status
✅ Stable with WordPress 6.7

✅ Tested with PHP 8.0 – 8.3

✅ All features tested

✅ Production-ready

📌 License
GPL v2 or later
Full license: https://www.gnu.org/licenses/gpl-2.0.html

👨‍💻 Author
Sergey Soloshenko (RuCoder)
🛠 WordPress / Full Stack Developer
📬 support@рукодер.рф
📲 Telegram: @RussCoder
🌐 https://рукодер.рф
