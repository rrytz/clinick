<?php
/**
 * DiagnosisClassifier.php — Symptom-to-condition Naive Bayes classifier.
 *
 * Handles symptom assessment, distress intent detection, bilingual emergency screening,
 * clause-aware negation detection, hyperbolic false-positive filtering, and qualitative confidence tier mapping.
 */

if (!class_exists('NaiveBayesClassifier')) {
    require_once __DIR__ . '/NaiveBayesClassifier.php';
}

/**
 * KNOWN THESIS LIMITATION:
 * Emergency terms in this classifier are deliberately scoped strictly to unambiguous medical emergency phrases
 * (e.g. chest pain, difficulty breathing, stroke symptoms). Generic single words (e.g. "help", "gonna", "wrong", "kaya")
 * are explicitly excluded to eliminate false positives on routine administrative and appointment queries.
 * Broad multi-language coverage of cardiac/neurological/allergic emergency phrasings in Cebuano/Tagalog is documented
 * as a known limitation for future extension. Suicidal ideation and mental health crisis detection are handled
 * separately via the dedicated CrisisDetector class before LLM/symptom processing.
 */
class DiagnosisClassifier
{
    private NaiveBayesClassifier $classifier;

    private const TRAINING_DATA = [
        // Upper Respiratory Infection / common cold
        ['text' => 'runny nose sneezing sore throat mild cough', 'label' => 'Upper Respiratory Infection'],
        ['text' => 'stuffy nose congestion sneezing', 'label' => 'Upper Respiratory Infection'],
        ['text' => 'sore throat cough runny nose', 'label' => 'Upper Respiratory Infection'],
        ['text' => 'mild fever cough congestion', 'label' => 'Upper Respiratory Infection'],
        ['text' => 'sipon ubo masakit ang lalamunan', 'label' => 'Upper Respiratory Infection'],
        ['text' => 'sinat ubo sipon', 'label' => 'Upper Respiratory Infection'],

        // Influenza-like illness
        ['text' => 'high fever body aches chills fatigue headache', 'label' => 'Influenza-like Illness'],
        ['text' => 'fever muscle pain tiredness headache cough', 'label' => 'Influenza-like Illness'],
        ['text' => 'sudden fever chills body pain weakness', 'label' => 'Influenza-like Illness'],
        ['text' => 'mataas na lagnat trangkaso pananakit ng katawan', 'label' => 'Influenza-like Illness'],

        // Migraine / tension headache
        ['text' => 'severe headache sensitivity to light nausea', 'label' => 'Migraine or Tension Headache'],
        ['text' => 'throbbing headache one side of head nausea', 'label' => 'Migraine or Tension Headache'],
        ['text' => 'headache pressure behind eyes stress', 'label' => 'Migraine or Tension Headache'],
        ['text' => 'masakit ang ulo sumasakit ulo', 'label' => 'Migraine or Tension Headache'],

        // Gastrointestinal upset
        ['text' => 'stomach pain nausea vomiting diarrhea', 'label' => 'Gastrointestinal Upset'],
        ['text' => 'stomach ache bloating after eating', 'label' => 'Gastrointestinal Upset'],
        ['text' => 'nausea vomiting loose stools', 'label' => 'Gastrointestinal Upset'],
        ['text' => 'masakit ang tiyan nagsusuka nagtatae', 'label' => 'Gastrointestinal Upset'],

        // Allergic reaction (non-emergency)
        ['text' => 'itchy skin rash sneezing watery eyes', 'label' => 'Allergic Reaction'],
        ['text' => 'skin itching hives after eating', 'label' => 'Allergic Reaction'],
        ['text' => 'watery eyes itchy nose sneezing seasonal', 'label' => 'Allergic Reaction'],

        // Urinary tract concern
        ['text' => 'burning sensation when urinating frequent urination', 'label' => 'Possible Urinary Tract Infection'],
        ['text' => 'painful urination lower abdominal discomfort', 'label' => 'Possible Urinary Tract Infection'],
        ['text' => 'masakit umihi balisawsaw', 'label' => 'Possible Urinary Tract Infection'],

        // Musculoskeletal / minor injury
        ['text' => 'muscle soreness after exercise joint pain', 'label' => 'Musculoskeletal Strain'],
        ['text' => 'back pain from lifting stiffness', 'label' => 'Musculoskeletal Strain'],
        ['text' => 'masakit ang likod pasma', 'label' => 'Musculoskeletal Strain'],
    ];

    private const EMERGENCY_TERMS = [
        // English Chest Pain & Cardiac
        'chest pain', 'severe chest pain', 'chestpain', 'crushing pain', 'crushing chest pain',
        // Filipino / Taglish Chest Pain
        'masakit ang dibdib', 'sobrang sakit ng dibdib', 'pananakit ng dibdib', 'sakit sa dibdib', 'masakit dibdib', 'kumikirot ang dibdib',
        // English Breathing / Respiratory
        'difficulty breathing', 'shortness of breath', 'short of breath', 'cant breathe', 'can\'t breathe', 'cannot breathe', 'not breathing', 'gasping for air',
        // Filipino / Taglish Breathing
        'hirap huminga', 'hindi makahinga', 'nahihirapang huminga', 'nahihirapan huminga', 'hirap humingga', 'di makahinga', 'hating hininga', 'hating-hininga',
        // English Loss of Consciousness
        'unconscious', 'passed out', 'fainted', 'loss of consciousness',
        // Filipino / Taglish Loss of Consciousness
        'walang malay', 'nawalan ng malay', 'nawalan malay', 'di nagkamalay', 'himatay',
        // English Neurological / Stroke / Trauma / Severe Bleeding
        'seizure', 'stroke', 'stroke symptoms', 'severe bleeding', 'anaphylaxis', 'severe allergic reaction', 'blue lips', 'severe head injury',
        // Filipino / Taglish Neurological / Emergency
        'biglang nanghina', 'hindi makapagsalita', 'di makapagsalita', 'dugo sa ubo', 'namamaluktot',
    ];

    private const DISTRESS_TERMS = [
        'gonna die', 'going to die', 'think im dying', 'think i\'m dying', 'i am dying', 'im dying', 'i\'m dying', 'i might die',
        'something is seriously wrong', 'cant take it', 'can\'t take it', 'cannot take it',
        'pakiramdam ko mamamatay na ako', 'mamamatay na ako', 'parang mamamatay ako', 'di ko na kaya', 'akala ko mamamatay na ako', 'akala ko mamamatay ako'
    ];

    private const HYPERBOLIC_PATTERNS = [
        'die laughing', 'dying laughing', 'died laughing', 'laughing so hard', 'laughing i thought',
        'workload is killing', 'work is killing', 'job is killing', 'homework is killing', 'school is killing',
        'exam almost killed', 'exam killed', 'test killed', 'exam nearly killed', 'nearly killed me',
        'scared me to death', 'bored to death', 'starving to death', 'scary i thought', 'so scary i thought'
    ];

    private const NEGATION_WORDS = [
        'no', 'not', 'without', 'denies', 'deny', 'free of', 'negative for',
        'wala', 'walang', 'hindi', 'di', 'ayaw', 'malayo sa'
    ];

    private const CLAUSE_BOUNDARIES = [
        'but', 'however', 'although',
        'pero', 'ngunit', 'subalit',
        ',', '.', ';', ':', '!'
    ];

    public function __construct()
    {
        $this->classifier = new NaiveBayesClassifier();
        $this->classifier->train(self::TRAINING_DATA);
    }

    public function isHyperbolic(string $symptomText): bool
    {
        $lower = strtolower($symptomText);
        foreach (self::HYPERBOLIC_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public function isDistressIntent(string $symptomText): bool
    {
        if ($this->isHyperbolic($symptomText)) {
            return false;
        }

        $lower = strtolower($symptomText);
        $preparedText = preg_replace('/([,.:;!])/u', ' $1 ', $lower);
        $words = preg_split('/\s+/', trim($preparedText));

        foreach (self::DISTRESS_TERMS as $term) {
            if (str_contains($lower, $term)) {
                if ($this->isTermNegated($words, $term)) {
                    continue;
                }
                return true;
            }
        }
        return false;
    }

    public function isEmergency(string $symptomText): bool
    {
        if ($this->isHyperbolic($symptomText)) {
            return false;
        }

        if ($this->isDistressIntent($symptomText)) {
            return true;
        }

        $lower = strtolower($symptomText);
        $preparedText = preg_replace('/([,.:;!])/u', ' $1 ', $lower);
        $words = preg_split('/\s+/', trim($preparedText));

        foreach (self::EMERGENCY_TERMS as $term) {
            if (str_contains($lower, $term)) {
                if ($this->isTermNegated($words, $term)) {
                    continue; // Suppress emergency trigger for negated terms in the SAME clause
                }
                return true; // Unnegated emergency match found!
            }
        }
        return false;
    }

    private function isTermNegated(array $words, string $term): bool
    {
        $termWords = preg_split('/\s+/', trim(strtolower($term)));
        $firstTermWord = $termWords[0];

        foreach ($words as $idx => $word) {
            if ($word === $firstTermWord) {
                $start = max(0, $idx - 4);
                for ($i = $idx - 1; $i >= $start; $i--) {
                    $prevWord = $words[$i];

                    // If a clause boundary is hit, STOP scanning backwards immediately!
                    if (in_array($prevWord, self::CLAUSE_BOUNDARIES, true)) {
                        break;
                    }

                    // If a negation word is hit before any clause boundary, it is negated
                    if (in_array($prevWord, self::NEGATION_WORDS, true)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function getConfidenceTier(float $rawProb): string
    {
        if ($rawProb >= 0.70) {
            return 'High Confidence';
        } elseif ($rawProb >= 0.40) {
            return 'Moderate Confidence';
        }
        return 'Low Confidence';
    }

    /**
     * @return array{isEmergency: bool, category?: string, confidence?: float, confidenceTier?: string, disclaimer: string}
     */
    public function classify(string $symptomText): array
    {
        $disclaimer = 'This is a general information suggestion only, not a medical diagnosis. Please consult a doctor for an accurate assessment.';

        if ($this->isEmergency($symptomText)) {
            return [
                'isEmergency'     => true,
                'urgencyLevel'    => 'EMERGENCY ESCALATION',
                'confidenceTier'  => 'High Confidence',
                'disclaimer'      => 'This may be a medical emergency. Please contact hospital staff immediately or call emergency services (911 / hotline) — do not rely on this assistant for urgent symptoms.',
            ];
        }

        $result    = $this->classifier->classify($symptomText);
        $rawProb   = round($result['confidence'], 3);
        $confTier  = $this->getConfidenceTier($rawProb);

        return [
            'isEmergency'    => false,
            'urgencyLevel'   => 'Normal Consultation',
            'category'       => $result['label'],
            'confidence'     => $rawProb,
            'confidenceTier' => $confTier,
            'disclaimer'     => $disclaimer,
        ];
    }
}
