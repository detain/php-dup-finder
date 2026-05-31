<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\QuizQuestionRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class QuizLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly QuizQuestionRepository $questionRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedQuestion(int $questionId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildQuestionCacheKey($questionId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'quiz_question', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'quiz_question', 'locale' => $locale]);

        $question = $this->questionRepository->find($questionId);

        if ($question === null) {
            return null;
        }

        $data = $this->translateQuestion($question, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getQuizQuestions(string $quizId, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildQuizCacheKey($quizId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $questions = $this->questionRepository->findByQuizId($quizId);

        $results = [];
        foreach ($questions as $question) {
            $results[] = $this->translateQuestion($question, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateQuestion(int $questionId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildQuestionCacheKey($questionId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $question = $this->questionRepository->find($questionId);
        if ($question !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $quizKey = $this->buildQuizCacheKey($question->getQuizId(), $l);
                $this->translator->invalidateCache($quizKey);
            }
        }

        $this->logger->debug('Invalidated quiz question localization', [
            'question_id' => $questionId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('quiz_question:*:' . $locale);

        $this->logger->info('Invalidated all quiz questions for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateQuestionTranslation(int $questionId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildQuestionCacheKey($questionId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'quiz_question',
            'question_id' => (string) $questionId,
            'locale' => $locale,
        ]);
    }

    private function buildQuestionCacheKey(int $questionId, string $locale): string
    {
        return "quiz_question:{$questionId}:{$locale}";
    }

    private function buildQuizCacheKey(string $quizId, string $locale): string
    {
        return "quiz:{$quizId}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateQuestion(object $question, string $locale): array
    {
        return [
            'id' => $question->getId(),
            'quiz_id' => $question->getQuizId(),
            'question_text' => $this->translator->translate($question->getQuestionTextKey(), $locale),
            'options' => $this->translateOptions($question->getOptionsKey(), $locale),
            'correct_option_index' => $question->getCorrectOptionIndex(),
            'explanation' => $this->translator->translate($question->getExplanationKey(), $locale),
            'locale' => $locale,
        ];
    }

    private function translateOptions(string $optionsKey, string $locale): array
    {
        $options = $this->translator->translate($optionsKey, $locale);
        return is_array($options) ? $options : [];
    }
}
