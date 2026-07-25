<?php
/**
 * Multinomial Naive Bayes text classifier — pure PHP, no framework dependency.
 * Works inside Laravel (app/Services/) or as a plain include in vanilla PHP.
 *
 * Same algorithm as the original TypeScript version: log-probabilities to
 * avoid underflow, Laplace (add-one) smoothing so unseen words don't zero
 * out a class entirely.
 *
 * P(class | words) ∝ P(class) * Π P(word_i | class)
 */

if (!class_exists('NaiveBayesClassifier')) {
class NaiveBayesClassifier
{
    private array $vocabulary = [];
    private array $classWordCounts = [];   // [label => [word => count]]
    private array $classTotalWords = [];   // [label => int]
    private array $classDocCounts = [];    // [label => int]
    private int $totalDocs = 0;
    private bool $trained = false;

    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        // strip accents so "pô" behaves like "po"
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $tokens = preg_split('/\s+/', trim($text));
        return array_values(array_filter($tokens, fn($t) => strlen($t) > 0));
    }

    /**
     * @param array $rows Array of ['text' => string, 'label' => string]
     */
    public function train(array $rows): void
    {
        foreach ($rows as $row) {
            $words = $this->tokenize($row['text']);
            $label = $row['label'];

            $this->totalDocs++;
            $this->classDocCounts[$label] = ($this->classDocCounts[$label] ?? 0) + 1;

            if (!isset($this->classWordCounts[$label])) {
                $this->classWordCounts[$label] = [];
                $this->classTotalWords[$label] = 0;
            }

            foreach ($words as $word) {
                $this->vocabulary[$word] = true;
                $this->classWordCounts[$label][$word] =
                    ($this->classWordCounts[$label][$word] ?? 0) + 1;
                $this->classTotalWords[$label]++;
            }
        }
        $this->trained = true;
    }

    /**
     * @return array{label: string, confidence: float, scores: array<string,float>}
     */
    public function classify(string $text): array
    {
        if (!$this->trained) {
            throw new \RuntimeException('NaiveBayesClassifier: call train() before classify()');
        }

        $words = $this->tokenize($text);
        $vocabSize = count($this->vocabulary);
        $logScores = [];

        foreach (array_keys($this->classDocCounts) as $label) {
            $priorProb = $this->classDocCounts[$label] / $this->totalDocs;
            $logScore = log($priorProb);

            $wordMap = $this->classWordCounts[$label];
            $totalWordsInClass = $this->classTotalWords[$label] ?? 0;

            foreach ($words as $word) {
                $wordCount = $wordMap[$word] ?? 0;
                // Laplace smoothing
                $wordProb = ($wordCount + 1) / ($totalWordsInClass + $vocabSize);
                $logScore += log($wordProb);
            }
            $logScores[$label] = $logScore;
        }

        $maxLog = max($logScores);
        $expScores = [];
        $sumExp = 0.0;
        foreach ($logScores as $label => $score) {
            $e = exp($score - $maxLog); // numerical stability
            $expScores[$label] = $e;
            $sumExp += $e;
        }

        $bestLabel = '';
        $bestProb = -1.0;
        foreach ($expScores as $label => $e) {
            $prob = $e / $sumExp;
            if ($prob > $bestProb) {
                $bestProb = $prob;
                $bestLabel = $label;
            }
        }

        return [
            'label' => $bestLabel,
            'confidence' => $bestProb,
            'scores' => $logScores,
        ];
    }
}
}
