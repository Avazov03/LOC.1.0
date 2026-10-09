<?php

namespace App\Telegram;

use App\Services\Attendance\AttendanceOutcome;

/**
 * Student-facing sentences (Uzbek Latin, A35). Never coordinates, radius values or internal ids.
 */
final class BotText
{
    public const GENERIC_FAILURE = 'Bu amalni hozir bajarib bo‘lmadi. Bir necha soniyadan keyin qayta urinib ko‘ring.';

    public const ACCESS_DENIED = '⛔ Ruxsat yo‘q. Siz tizimda faol talaba sifatida ro‘yxatdan o‘tmagansiz. Universitet bergan taklif havolasi orqali kiring yoki rahbaringizga murojaat qiling.';

    public const NO_ACTIVE_ASSIGNMENT = 'Sizda hozir faol amaliyot biriktirilmagan.';

    public const NEED_INVITE = 'Ro‘yxatdan o‘tish uchun universitet bergan taklif havolasini oching.';

    public const ALREADY_REGISTERED = 'Siz allaqachon ro‘yxatdan o‘tgansiz.';

    public const RATE_LIMITED = 'Juda ko‘p so‘rov yuborildi. Bir daqiqadan keyin qayta urinib ko‘ring.';

    public const ASK_LOCATION = 'Davomatni tasdiqlash uchun hozirgi joylashuvingizni yuboring. Pastdagi «📍 Joylashuvni yuborish» tugmasini bosing.';

    public const LOCATION_WITHOUT_ACTION = 'Joylashuv qabul qilinmadi. Avval «🟢 Amaliyotni boshlash» yoki «🔴 Amaliyotni tugatish» tugmasini bosing.';

    public const CANCELLED = 'Amal bekor qilindi.';

    public const UNKNOWN = 'Quyidagi menyudan kerakli bo‘limni tanlang.';

    public const CHECKOUT_REMINDER = "⏰ Amaliyotni tugatishni unutmang!\n\nBugungi davomatingiz hali ochiq. Amaliyot joyidan ketayotganda «🔴 Amaliyotni tugatish» tugmasini bosing, aks holda kun «Yakunlanmagan» bo‘lib qoladi.";

    public const NO_COORDINATES = 'Koordinata yoki xarita havolasi yubormang. Tashkilot joylashuvini universitet administratori belgilaydi.';

    public const HELP = <<<'TXT'
        ❓ Yordam

        🟢 Amaliyotni boshlash — amaliyot joyiga kelganingizni qayd etadi.
        🔴 Amaliyotni tugatish — amaliyot joyidan ketayotganingizni qayd etadi.
        📋 Mening amaliyotim — biriktirilgan tashkilot, manzil, muddat va rahbar.
        📅 Davomatim — so‘nggi kunlardagi davomatingiz.
        🔄 Amaliyot joyini o‘zgartirish — joyni almashtirish so‘rovini yuborish yoki bekor qilish.
        👤 Profilim — ism, telefon, guruh va talaba ID.

        Davomat faqat amal bajarilgan paytdagi joylashuvingiz bilan tasdiqlanadi. Joylashuvni boshqa xabardan uzatib yoki xaritadan tanlab yuborish qabul qilinmaydi. Tizim faqat kelish va ketish paytini qayd etadi.
        TXT;

    public static function outcome(AttendanceOutcome $outcome): string
    {
        $data = $outcome->data;

        return match ($outcome->code) {
            AttendanceOutcome::CHECKED_IN => self::lines([
                '✅ Amaliyot boshlanishi qayd etildi.',
                '',
                'Vaqt: '.$data['time'],
                'Joy: '.$data['organization'],
                $data['distance'] !== null ? 'Masofa: '.$data['distance'].' m' : null,
            ]),
            AttendanceOutcome::CHECKED_OUT => self::lines([
                '✅ Amaliyot tugashi qayd etildi.',
                '',
                'Vaqt: '.$data['time'],
                'Joy: '.$data['organization'],
                'Davomiylik: '.self::duration((int) $data['duration_seconds']),
                $data['distance'] !== null ? 'Masofa: '.$data['distance'].' m' : null,
            ]),
            AttendanceOutcome::OUTSIDE_RADIUS => self::lines([
                '❌ Amaliyot joyidan tashqaridasiz.',
                '',
                'Belgilangan joydan taxminan '.$data['distance'].' metr uzoqdasiz.',
                'Amaliyot joyiga yaqinlashib, qayta urinib ko‘ring.',
            ]),
            AttendanceOutcome::LOW_ACCURACY => '❌ Joylashuv aniqligi past'.($data['accuracy'] !== null ? ' ('.$data['accuracy'].' m)' : '').'. Telefoningizda GPS’ni yoqing va joylashuvni qayta yuboring.',
            AttendanceOutcome::INVALID_LOCATION => '❌ Joylashuv noto‘g‘ri. Joylashuvni qayta yuboring.',
            AttendanceOutcome::FORWARDED_LOCATION => '❌ Uzatilgan yoki xaritadan tanlangan joylashuv qabul qilinmaydi. Hozirgi joylashuvingizni «📍 Joylashuvni yuborish» tugmasi orqali yuboring.',
            AttendanceOutcome::MAP_LOCATION => self::lines([
                '❌ Xaritadan tanlangan nuqta qabul qilinmaydi.',
                'Telefoningizda GPS (joylashuv) yoqilganiga ishonch hosil qiling va pastdagi «📍 Joylashuvni yuborish» tugmasini bosing.',
            ]),
            AttendanceOutcome::STALE_LOCATION => '❌ Bu joylashuv kechikib yetib keldi. «📍 Joylashuvni yuborish» tugmasini qayta bosing.',
            AttendanceOutcome::REUSED_LOCATION => self::lines([
                '❌ Bu joylashuv avval yuborilgan nuqta bilan aynan bir xil, shuning uchun qabul qilinmaydi.',
                'Saqlangan yoki boshqa kishidan olingan joylashuv ishlamaydi. Amaliyot joyida turib «📍 Joylashuvni yuborish» tugmasini bosing.',
            ]),
            AttendanceOutcome::LOCATION_MISSING => 'Joylashuv yuborish kerak. «📍 Joylashuvni yuborish» tugmasini bosing.',
            AttendanceOutcome::NO_ASSIGNMENT => self::NO_ACTIVE_ASSIGNMENT,
            AttendanceOutcome::OUTSIDE_PERIOD => 'Amaliyot muddatidan tashqarida davomat qabul qilinmaydi.',
            AttendanceOutcome::NOT_WORK_DAY => "📅 Bugun sizning amaliyot kuningiz emas.\nAmaliyot kunlaringiz: {$data['days']}.\nBu kun «Kelmadi» deb hisoblanmaydi.",
            AttendanceOutcome::ORGANIZATION_INACTIVE => 'Amaliyot joyingiz hozir faol emas. Rahbaringizga murojaat qiling.',
            AttendanceOutcome::DUPLICATE_OPEN => 'Sizda allaqachon faol davomat mavjud. Ketayotganda «🔴 Amaliyotni tugatish» tugmasini bosing.',
            AttendanceOutcome::SECOND_SESSION_BLOCKED => 'Bugungi davomat allaqachon qayd etilgan. Bir kunda ikkinchi marta boshlashga ruxsat yo‘q.',
            AttendanceOutcome::CHECK_IN_DISABLED, AttendanceOutcome::CHECK_OUT_DISABLED => 'Bu amal hozir mavjud emas.',
            AttendanceOutcome::NO_OPEN_SESSION => 'Sizda yopiladigan faol davomat mavjud emas.',
            AttendanceOutcome::ACCESS_DENIED => self::ACCESS_DENIED,
            default => self::GENERIC_FAILURE,
        };
    }

    public static function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? "{$hours} soat {$minutes} daqiqa" : "{$minutes} daqiqa";
    }

    /**
     * @param  list<?string>  $lines
     */
    public static function lines(array $lines): string
    {
        return implode("\n", array_filter($lines, fn (?string $line) => $line !== null));
    }
}
