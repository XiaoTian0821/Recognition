<?php
declare(strict_types=1);

/**
 * Object recognition orchestrator.
 *
 * Takes a validated base64 image and runs it through the configured provider
 * chain (Auto = Gemini then Agnes, or a single provider). Provider order is
 * built dynamically so fallbacks keep working even when config files are
 * missing or only partially filled in.
 */
final class ObjectRecognizer
{
    /**
     * Recognize the main object in an image.
     *
     * @param string $binaryData raw decoded image bytes (from ImageValidator)
     * @param string $mime       image/jpeg | image/png
     * @return ProductResult
     * @throws RecognitionException when no provider could produce a result
     * @throws ProviderNotConfiguredException when no provider is usable at all
     */
    public function recognize(string $binaryData, string $mime): ProductResult
    {
        $config = AppConfig::load();
        $mode = $config->providerMode();

        /** @var array<int, array{provider: AIProvider, label: string}> $chain */
        $chain = [];
        $gemini = new GeminiVision();
        $agnes = new AgnesVision();

        switch ($mode) {
            case 'gemini':
                $chain = [[ 'provider' => $gemini, 'label' => 'gemini' ]];
                break;
            case 'agnes':
                $chain = [[ 'provider' => $agnes, 'label' => 'agnes' ]];
                break;
            default: // auto: try Gemini first, then Agnes.
                $chain = [
                    ['provider' => $gemini, 'label' => 'gemini'],
                    ['provider' => $agnes, 'label' => 'agnes'],
                ];
        }

        $notConfigured = 0;
        $details = [];

        $base64Data = base64_encode($binaryData);

        foreach ($chain as $entry) {
            $provider = $entry['provider'];
            try {
                $rawData = $provider->request($base64Data, $mime);
            } catch (ProviderNotConfiguredException $e) {
                $notConfigured++;
                $details[] = $e->getMessage();
                continue; // This provider has no key - try the next one.
            } catch (ProviderException $e) {
                $details[] = $e->getMessage();
                continue; // Provider error - fall back to the next one.
            }

            try {
                $result = ProductResult::fromProviderData($rawData, [
                    'provider' => $entry['label'],
                    'model' => $this->modelUsedFor($provider, $entry['label']),
                ]);
                // Optional refinement with the (trusted, same-host) web lookup.
                $lookup = new ProductLookup($config);
                $result->applyLookup($lookup->refine($result));
                return $result;
            } catch (InvalidArgumentException $e) {
                $details[] = $e->getMessage();
                continue; // Bad structured answer - try the next provider.
            }
        }

        if ($notConfigured === count($chain)) {
            throw new ProviderNotConfiguredException(
                'No AI provider is configured. Add a Gemini or Agnes API key in Settings.'
            );
        }
        throw new RecognitionException('Unable to identify the object.', $details);
    }

    /**
     * Which model id was likely used (for display only; the provider may have
     * fallen back through its own model list, so treat this as informational).
     *
     * @param AIProvider $provider
     * @param string $label
     */
    private function modelUsedFor(AIProvider $provider, string $label): string
    {
        $config = AppConfig::load();
        $list = $label === 'gemini' ? $config->geminiModels() : $config->agnesModels();
        return (string) ($list[0] ?? '');
    }
}
