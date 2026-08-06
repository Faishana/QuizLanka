<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI Avatar Prompt
    |--------------------------------------------------------------------------
    */

    'prompt' => <<<PROMPT
Transform the uploaded portrait into a premium semi-realistic 3D cartoon avatar for an educational mobile application.

Requirements:
- Preserve the person's identity and recognizable facial features.
- Preserve face shape, hairstyle, hair color, eyebrows, eye color, and skin tone.
- Preserve glasses and other permanent accessories if present.
- Preserve the person's age appearance and gender presentation.
- Keep the same facial expression while making it warm, friendly, and approachable.
- Create a premium semi-realistic 3D illustrated avatar suitable for a modern mobile application.
- Head and shoulders only.
- Face looking directly at the camera.
- Center the subject.
- Plain soft blue to purple gradient background.
- Soft studio lighting.
- Clean and minimal composition.
- Square (1:1) composition.
- High resolution.
- No text.
- No watermark.
- No logos.
- No additional people.
- No extra objects.
- Do not crop the top of the hair or chin.
- Suitable for students of all ages.
PROMPT,

];
