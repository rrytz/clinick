<?php
/**
 * CrisisDetector.php — Deterministic Trilingual Crisis & Self-Harm Interceptor
 *
 * Dedicated standalone classifier for suicidal ideation, self-harm, hopelessness, and severe crisis expressions.
 * Operates purely on simple deterministic substring phrase matching BEFORE memory loading or Gemini execution.
 * Returns language-matched empathetic responses in English, Filipino (Tagalog), or Cebuano (Visayan).
 * Completely separate from DiagnosisClassifier (physical symptom assessment).
 */

class CrisisDetector
{
    private const CRISIS_PHRASES_EN = [
        'want to die', 'wanna die', 'wanting to die',
        'want to end it', 'wanna end it', 'want to end my life', 'wanna end my life',
        'kill myself', 'killing myself', 'kill my self',
        'end my life', 'ending my life',
        'take my own life', 'taking my own life',
        'better off dead', 'rather be dead',
        'don\'t want to live', 'dont want to live', 'do not want to live', 'no reason to live', 'not worth living',
        'tired of living', 'tired of life', 'done with life',
        'wish i was dead', 'wish i were dead', 'wish i would die', 'wish i could die',
        'sleep and never wake up', 'go to sleep and not wake up',
        'hurt myself', 'hurting myself', 'harm myself', 'harming myself', 'self harm', 'self-harm',
        'cut myself', 'cutting myself',
        'hang myself', 'hanging myself',
        'overdose', 'jump off',
        'end it all', 'ending it all',
        'goodbye world', 'final goodbye',
        'no way out', 'no point in living', 'life is not worth',
        'suicidal', 'suicide',
    ];

    private const CRISIS_PHRASES_TL = [
        'gusto ko nang mamatay', 'gusto ko na mamatay', 'gusto kong mamatay', 'gusto ko mamatay',
        'mamatay na sana ako', 'mamatay na ako', 'mamatay nako', 'sana mamatay na',
        'ayoko na mabuhay', 'ayaw ko na mabuhay', 'ayoko nang mabuhay', 'ayaw ko nang mabuhay',
        'magpakamatay', 'magpakamatay na', 'magpapakamatay',
        'tatapusin ko na', 'tatapusin ko na ang buhay ko', 'tatapusin ko na buhay ko',
        'walang kuwenta ang buhay', 'walang kwenta ang buhay', 'walang silbi ang buhay',
        'patayin ang sarili', 'patayin ko sarili ko', 'patayin sarili',
        'mas mabuting mamatay', 'mabuti pang mamatay', 'mabuti pa mamatay',
        'nasasaktan ko sarili ko', 'saktan ang sarili', 'saktan sarili',
        'kitlin ang buhay', 'sariling buhay',
        'gusto ko na matapos ang lahat', 'gusto ko nang matapos lahat',
        'pagod na ako mabuhay', 'pagod na akong mabuhay', 'pagod na ko mabuhay',
    ];

    private const CRISIS_PHRASES_CEB = [
        'gusto na ko mamatay', 'gusto nako mamatay', 'gusto na nako mamatay',
        'ganahan na ko mamatay', 'ganahan nako mamatay', 'ganahan na nako mamatay',
        'di na ko ganahan mabuhi', 'dili na ko ganahan mabuhi', 'dili na ko gusto mabuhi', 'di na ko gusto mabuhi',
        'mag-hukom sa kinabuhi', 'maghukom sa kinabuhi',
        'mag-utod sa kinabuhi', 'magutod sa kinabuhi', 'utdon ang kinabuhi',
        'mas maayo pa mamatay', 'maayo pa mamatay', 'maayo pa mamatay na lang',
        'patyon nako akong sarili', 'patyon nako akong kaugalingon',
        'hikit-an na lang ko ninyo patay', 'hikog', 'mag-hikog', 'maghikog',
        'kutloon ang kinabuhi', 'kutlo-on ang kinabuhi',
        'kapoy na mabuhi', 'kapoy na kaayo mabuhi', 'kapoy na kaayo ang kinabuhi',
        'dili na nako kaya ang kinabuhi', 'di na nako kaya ang kinabuhi',
        'gusto na nako tapuson ang tanan', 'gusto na ko magpakamatay',
    ];

    public static function detectLanguage(string $message): ?string
    {
        $lower = strtolower($message);

        // Check Cebuano first to prevent overlap with Tagalog phrases
        foreach (self::CRISIS_PHRASES_CEB as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'ceb';
            }
        }

        foreach (self::CRISIS_PHRASES_TL as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'tl';
            }
        }

        foreach (self::CRISIS_PHRASES_EN as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'en';
            }
        }

        return null;
    }

    public static function isCrisisMessage(string $message): bool
    {
        return self::detectLanguage($message) !== null;
    }

    public static function getCrisisResponse(string $message): string
    {
        $lang = self::detectLanguage($message) ?? 'en';

        if ($lang === 'ceb') {
            return "Nakabati ko nga nag-agi ka ug bug-at kaayo nga kasakit karon, ug gusto nako mahibaloan nimo nga dili ka nag-inusara. Naa kami para motabang kanimo.\n\n" .
                   "Kon ikaw o aduna kay kaila nga nag-antos o adunay hunahuna sa pagpasakit sa kaugalingon, palihog ikontak dayon kini nga libre ug confidential nga suporta:\n" .
                   "• National Center for Mental Health Crisis Hotline: 1553 (nationwide, toll-free) o 1800-1888-1553\n" .
                   "• Globe / TM: 0917-899-8727\n" .
                   "• Smart / TNT: 0919-057-1553\n\n" .
                   "Mahimo usab ka nga direktang makig-istorya sa among clinic staff o reception alang sa dali nga tabang.";
        }

        if ($lang === 'tl') {
            return "Nadedama ko na dumadaan ka sa napakabigat na pagsubok ngayon, at gusto kong malaman mo na hindi mo kailangang mag-isa. Nandito kami para sa iyo.\n\n" .
                   "Kung ikaw o ang isang kakilala mo ay nahihirapan o may naiisip na saktan ang sarili, mangyaring tumawag agad sa libre at confidential na suporta:\n" .
                   "• National Center for Mental Health Crisis Hotline: 1553 (nationwide, toll-free) o 1800-1888-1553\n" .
                   "• Globe / TM: 0917-899-8727\n" .
                   "• Smart / TNT: 0919-057-1553\n\n" .
                   "Maaari ka ring direktang makipag-ugnayan sa aming clinic staff o reception para sa agarang tulong.";
        }

        return "I'm hearing that you are going through a really difficult time right now, and I want to support you. You don't have to carry this alone.\n\n" .
               "If you or someone you know is in distress or having thoughts of self-harm, please reach out immediately for free, confidential support:\n" .
               "• National Center for Mental Health Crisis Hotline: 1553 (nationwide, toll-free) or 1800-1888-1553\n" .
               "• Globe / TM: 0917-899-8727\n" .
               "• Smart / TNT: 0919-057-1553\n\n" .
               "You can also connect directly with hospital staff or reception for immediate assistance.";
    }
}
