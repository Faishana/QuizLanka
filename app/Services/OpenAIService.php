<?php

namespace App\Services;

use OpenAI;

class OpenAIService
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::client(
            env('OPENAI_API_KEY')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate MCQs
    |--------------------------------------------------------------------------
    */

    public function generateQuestions(
    string $text,
    int $questionCount = 3
)
{
    $prompt = <<<PROMPT

ඔබ ශ්‍රී ලංකාවේ පාසල් විභාග ප්‍රශ්න පත්‍ර සකස් කරන ජ්‍යෙෂ්ඨ ගුරුවරයෙකි.

පහත පාඩම් අන්තර්ගතය පමණක් භාවිතා කර MCQ ප්‍රශ්න {$questionCount} ක් සාදන්න.

අනිවාර්ය නීති:

1. ප්‍රශ්න සිංහලෙන් විය යුතුය.
2. පිළිතුරු විකල්ප සිංහලෙන් විය යුතුය.
3. පැහැදිලි කිරීම සිංහලෙන් විය යුතුය.
4. ලබා දී ඇති පාඩම් අන්තර්ගතයට පිටින් තොරතුරු භාවිතා නොකරන්න.
5. එකම කරුණ හෝ සංකල්පය නැවත නැවත ප්‍රශ්න නොකරන්න.
6. පාඩමේ වැදගත් සංකල්ප, නිර්වචන, භාවිතයන් සහ උදාහරණ ආවරණය කරන්න.
7. සෑම ප්‍රශ්නයකටම A,B,C,D විකල්ප 4ක් තිබිය යුතුය.
8. නිවැරදි පිළිතුර එකක් පමණක් තිබිය යුතුය.
9. සියලු වැරදි විකල්ප (Distractors) තාර්කික හා අදාළ විය යුතුය.
10. difficulty අගය "easy", "medium", "hard" වලින් එකක් විය යුතුය.
11. JSON පමණක් return කරන්න.
12. {$questionCount} ට වඩා වැඩි හෝ අඩු ප්‍රශ්න ලබා නොදෙන්න.
13. HTML, CSS, JavaScript, Audacity, CPU, RAM, URL, Email, File, Folder වැනි තාක්ෂණික වචන අවශ්‍ය නම් English ලෙසම තබන්න.
14. HTML tags (<html>, <body>, <img>, <title>, <h1>) translate නොකරන්න.
15. ප්‍රශ්න කෙටි, පැහැදිලි සහ විභාග මට්ටමට සුදුසු විය යුතුය.
16. ප්‍රශ්නය, පිළිතුරු සහ පැහැදිලි කිරීම හිස් නොවිය යුතුය.
17. පාඩමේ අන්තර්ගතයෙන් සෘජුවම copy-paste නොකරන්න. අර්ථය අනුව ප්‍රශ්න සාදන්න.
18. ඉතා දිගු ප්‍රශ්න සෑදීමෙන් වළකින්න.
19. JSON format එක හැර වෙනත් කිසිදු text එකක් return නොකරන්න.
20. "යනු කුමක්ද?" ආකාරයේ ප්‍රශ්න ප්‍රමාණය 30% ට වඩා වැඩි නොවිය යුතුය.

21. පහත වර්ගවල ප්‍රශ්න මිශ්‍ර කර සාදන්න:
- Definition Questions
- Application Questions
- Scenario Based Questions
- Comparison Questions
- Problem Solving Questions

22. සිසුන්ගේ විශ්ලේෂණාත්මක චින්තනය මැනිය හැකි ප්‍රශ්න ඇතුළත් කරන්න.

23. සරල මතක ප්‍රශ්න පමණක් නොසාදන්න.
24. එක් chunk එකකින් සෑදෙන ප්‍රශ්න එකිනෙකාට පැහැදිලිව වෙනස් විය යුතුය.

25. එකම keyword එක භාවිතා කර ප්‍රශ්න කිහිපයක් නොසාදන්න.

26. "X යනු කුමක්ද?" ආකාරයේ ප්‍රශ්න 1කට වඩා නොසාදන්න.

Difficulty Distribution:

- Easy 40%
- Medium 40%
- Hard 20%

Return Format:

{
  "questions": [
    {
      "question": "ප්‍රශ්නය",
      "options": {
        "A": "විකල්පය",
        "B": "විකල්පය",
        "C": "විකල්පය",
        "D": "විකල්පය"
      },
      "correct_answer": "A",
      "difficulty": "easy",
      "explanation": "පැහැදිලි කිරීම"
    }
  ]
}

පාඩම් අන්තර්ගතය:

{$text}

PROMPT;

        $response = $this->client->chat()->create([

            'model' => 'gpt-4o-mini',

            'messages' => [
                [
                    'role' => 'system',
                    'content' => '
                    ඔබ ශ්‍රී ලංකාවේ සිංහල මාධ්‍ය ගුරුවරයෙකි.

                    සියලු output සිංහලෙන් පමණක් ලබා දෙන්න.

                    JSON පමණක් return කරන්න.
                    '
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],

            'temperature' => 0.2,

            'max_completion_tokens' => 4000,

            'response_format' => [
                'type' => 'json_object'
            ]
        ]);

        return $response
            ->choices[0]
            ->message
            ->content;
    }

    // Translate questions to Sinhala

    public function translateQuestionsToSinhala(array $questions)
    {
        $json = json_encode(
            ['questions' => $questions],
            JSON_UNESCAPED_UNICODE
        );

        $prompt = <<<PROMPT

    Translate the following MCQ questions into Sinhala.

    Rules:

    - Question must be Sinhala.
    - Options must be Sinhala.
    - Explanation must be Sinhala.
    - Keep option keys A,B,C,D unchanged.
    - Keep correct_answer unchanged.
    - Return ONLY valid JSON.

    {$json}

    PROMPT;

        $response = $this->client->chat()->create([

            'model' => 'gpt-4o-mini',

            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a professional Sinhala translator. Return JSON only.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],

            'temperature' => 0,

            'response_format' => [
                'type' => 'json_object'
            ]
        ]);

        return json_decode(
            $response->choices[0]->message->content,
            true
        );
    }

}
