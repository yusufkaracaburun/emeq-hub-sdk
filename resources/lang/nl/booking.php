<?php

declare(strict_types=1);

/*
 * Copy voor de uitkomsten die dit package bepaalt. Alles wat het boekscherm van
 * een consumer zelf zegt blijft in diens eigen taalbestanden — publiceer
 * `hub-translations` om deze teksten te herschrijven.
 */

return [

    'not_found' => 'Dit document bestaat niet meer.',
    'not_allowed' => 'Je mag dit document niet bewerken, dus ook niet boeken.',
    'temporarily_unavailable' => 'Er loopt al een boeking van dit document, of de boekhouding is even niet bereikbaar. Probeer het zo nog eens.',

    'error' => [
        'mapping_failed' => 'De boekhouding kent deze relatie of code nog niet.',
        'upstream_rejected' => 'De boekhouding heeft dit document inhoudelijk geweigerd.',
        'document_already_posted' => 'Dit document is al geboekt met een andere inhoud.',
        'idempotency_key_reuse' => 'Deze sleutel hoort al bij een ander document.',
        'insufficient_ability' => 'De koppeling mag niet boeken. Controleer de rechten van de koppeling.',
        'provider_disabled' => 'De koppeling met de boekhouding staat uit.',
        'connection_interrupted' => 'De verbinding met de boekhouding viel weg voordat er antwoord kwam. Controleer daar of het document geboekt is voordat je het opnieuw probeert.',
        'attachment_render_failed' => 'De bijlage kon niet gemaakt worden, dus er is niets geboekt.',
        'unknown' => 'De boekhouding gaf een onbekende foutmelding.',
    ],

];
