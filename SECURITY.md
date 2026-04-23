# Политика безопасности

Безопасность пользователей плагина **WP Ru-max** для нас в приоритете. Мы благодарны исследователям безопасности и пользователям, которые ответственно сообщают о найденных уязвимостях.

## Поддерживаемые версии

Обновления безопасности выпускаются только для актуальной мажорной ветки плагина. Пожалуйста, перед сообщением об уязвимости убедитесь, что используете последнюю версию.

| Версия   | Поддержка обновлениями безопасности |
| -------- | ----------------------------------- |
| 1.0.22   |  Поддерживается                    |
| 1.0.x (< 1.0.22) |  Только критические исправления |
| < 1.0.0  |  Не поддерживается                 |

Минимальные требования окружения, для которого выпускаются обновления безопасности:

- WordPress: **5.8** и выше
- PHP: **7.4** и выше

## Как сообщить об уязвимости

**Пожалуйста, не публикуйте информацию об уязвимостях в публичных GitHub Issues, Pull Requests или обсуждениях.** Это может подвергнуть риску действующие сайты, использующие плагин.

Сообщить об уязвимости можно одним из приватных способов:

1. **Через GitHub Security Advisories (предпочтительно):**  
   Перейдите на вкладку [Security → Advisories](https://github.com/RuCoder-sudo/wp-ru-max/security/advisories/new) репозитория и нажмите **«Report a vulnerability»**. Это создаст приватное обсуждение, видимое только вам и сопровождающим плагина.

2. **По электронной почте:**  
   Напишите на **[rucoder.rf@yandex.ru](mailto:rucoder.rf@yandex.ru)** с темой, начинающейся с `[SECURITY] WP Ru-max`.

3. **Через Telegram (для срочных случаев):**  
   Личное сообщение разработчику: [@RuCoder_official](https://t.me/RuCoder_official)

### Что включить в сообщение

Чтобы мы могли быстро воспроизвести и исправить проблему, по возможности приложите:

- Версию плагина WP Ru-max, версию WordPress и версию PHP
- Тип уязвимости (XSS, SQL-инъекция, CSRF, IDOR, RCE, утечка данных и т. д.)
- Подробное описание проблемы и потенциального воздействия
- Шаги для воспроизведения (proof of concept, пример запроса/полезной нагрузки)
- При необходимости — предлагаемое исправление или обходной путь
- Ваши контактные данные для уточняющих вопросов и упоминания в благодарностях (при желании)

## Сроки реагирования

Мы стремимся придерживаться следующих сроков:

| Этап                                       | Целевой срок          |
| ------------------------------------------ | --------------------- |
| Подтверждение получения сообщения          | в течение 72 часов    |
| Первичная оценка и классификация           | в течение 7 дней      |
| Регулярные обновления о ходе исправления   | каждые 7 дней         |
| Выпуск исправления для критических проблем | в течение 30 дней     |
| Публичное раскрытие через GitHub Advisory  | после выпуска патча   |

Время может варьироваться в зависимости от сложности уязвимости и доступности сопровождающих.

## Политика ответственного раскрытия информации

Мы придерживаемся практики **скоординированного раскрытия (Coordinated Disclosure)**:

- Пожалуйста, дайте нам разумное время на исправление **до публичного раскрытия** деталей уязвимости.
- Не используйте найденную уязвимость для доступа к данным других пользователей, изменения данных или нарушения работы сайтов.
- Не выполняйте автоматизированное сканирование производственных сайтов, использующих плагин, без согласия их владельцев.
- После выпуска исправления мы публикуем GitHub Security Advisory с CVE (при необходимости) и упоминаем исследователя в благодарностях, если он этого пожелает.

## Что находится в области безопасности

В область политики входят уязвимости в:

- Коде плагина WP Ru-max в этом репозитории
- Серверных AJAX-эндпоинтах, REST-маршрутах и обработчиках админ-меню плагина
- Шорткодах, виджетах и фронтенд-скриптах плагина (`assets/`)
- Механизме автообновления и проверки лицензии
- Логике интеграции с WooCommerce, Contact Form 7, Elementor Forms, Gravity Forms

В область **не** входят:

- Уязвимости в самом ядре WordPress, PHP или сторонних плагинах/темах (сообщайте их соответствующим разработчикам)
- Уязвимости лицензионного сервера на сайте [рукодер.рф](https://рукодер.рф) — для них действует отдельная политика по контактам выше
- Социальная инженерия, физический доступ, DoS-атаки на инфраструктуру, спам через формы
- Отсутствие «лучших практик», не приводящее к реальной эксплуатации (например, отсутствие отдельного HTTP-заголовка)

## Благодарности

Мы публикуем список исследователей, ответственно сообщивших об уязвимостях, в разделе [Security Advisories](https://github.com/RuCoder-sudo/wp-ru-max/security/advisories) репозитория (с согласия автора).

Спасибо, что помогаете делать WP Ru-max безопаснее для всего сообщества WordPress!

---

# Security Policy (English)

The security of **WP Ru-max** users is a top priority. We appreciate the work of security researchers and users who responsibly disclose vulnerabilities they find.

## Supported Versions

Security updates are provided only for the latest major branch. Please make sure you are running the latest version before reporting.

| Version        | Security updates             |
| -------------- | ---------------------------- |
| 1.0.22         |  Supported                  |
| 1.0.x (< 1.0.22) |  Critical fixes only      |
| < 1.0.0        |  Not supported              |

Minimum supported environment: WordPress **5.8+**, PHP **7.4+**.

## Reporting a Vulnerability

**Please do not disclose vulnerabilities in public GitHub Issues, Pull Requests, or Discussions.** Use one of the private channels below:

1. **GitHub Security Advisories (preferred):** [Report a vulnerability](https://github.com/RuCoder-sudo/wp-ru-max/security/advisories/new)
2. **Email:** [rucoder.rf@yandex.ru](mailto:rucoder.rf@yandex.ru) with subject starting `[SECURITY] WP Ru-max`
3. **Telegram (urgent):** [@RuCoder_official](https://t.me/RuCoder_official)

Please include: plugin/WordPress/PHP versions, vulnerability type, impact, reproduction steps (PoC), suggested fix (if any), and your contact for follow-up.

## Response Targets

| Stage                                   | Target              |
| --------------------------------------- | ------------------- |
| Acknowledgement of report               | within 72 hours     |
| Initial triage                          | within 7 days       |
| Status updates                          | every 7 days        |
| Fix released for critical issues        | within 30 days      |
| Public disclosure via GitHub Advisory   | after patch release |

We follow **coordinated disclosure**: please give us reasonable time to release a fix before publishing details. Do not access other users' data, modify data, or disrupt sites running the plugin while researching.

Acknowledged researchers are credited in published GitHub Security Advisories (with consent).

Thank you for helping keep WP Ru-max and the WordPress community safe!
