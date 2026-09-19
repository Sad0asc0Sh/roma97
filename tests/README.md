# تست‌های roma97

تست‌های خودکار برای رفع ایرادهای بحرانی (تاریخ شمسی، شماره موبایل والد، صف پیامک).

## پیش‌نیازها

- PHP CLI نسخه ۸ به بالا با افزونه‌های `pdo_mysql` و `mbstring`
- MySQL یا MariaDB در حال اجرا روی localhost

## اجرا

```bash
# اجرای همه تست‌ها
php tests/run-all-tests.php

# یا اجرای تکی هر سوئیت
php tests/jalali-date-test.php        # بدون نیاز به دیتابیس
php tests/sms-queue-reaper-test.php   # نیاز به دیتابیس
php tests/parent-phone-test.php       # نیاز به دیتابیس
```

## تنظیمات دیتابیس تست (اختیاری)

به‌صورت پیش‌فرض تست‌ها به دیتابیس **`roma97_test`** روی `127.0.0.1` با کاربر `root` و
بدون رمز وصل می‌شوند. این دیتابیس **حذف و از نو ساخته می‌شود** — هرگز آن را به
سمت دیتابیس واقعی تنظیم نکنید:

```bash
ROMA_TEST_DB_HOST=127.0.0.1 ROMA_TEST_DB_USER=root ROMA_TEST_DB_PASS= ROMA_TEST_DB_NAME=roma97_test \
  php tests/run-all-tests.php
```

برای امنیت، اگر فایل `config.local.php` وجود داشته باشد تست‌ها اجرا نمی‌شوند.

## سوئیت‌ها

| فایل | چه چیزی را تست می‌کند |
|---|---|
| `jalali-date-test.php` | پذیرش ارقام فارسی/عربی در `parseJalaliDate`، رد روزهای نامعتبر (مثل ۳۰ اسفند سال غیرکبیسه)، چرخه ۳۳ساله کبیسه |
| `sms-queue-reaper-test.php` | بازیابی پیامک‌های گیرکرده در وضعیت `sending` توسط `smsQueueProcess` (مسیر واقعی کد، بدون ارسال واقعی پیامک) |
| `parent-phone-test.php` | ایندکس UNIQUE روی `parents.phone`، گسترش ENUM کدهای تأیید برای نصب‌های قدیمی، چرخه تولید/تأیید OTP برای تغییر شماره |
| `test-bootstrap.php` | ابزار مشترک (assert، ساخت دیتابیس تست از `schema.sql`) — مستقیم اجرا نشود |
| `run-all-tests.php` | اجرای همه سوئیت‌ها و گزارش تجمیعی |
