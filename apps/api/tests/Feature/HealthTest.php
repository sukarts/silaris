<?php

declare(strict_types=1);

it('publie la version en service sur le point de santé', function (): void {
    // Le déploiement automatique s'appuie sur ce champ pour distinguer une
    // nouvelle version réellement tirée d'un conteneur resté sur l'ancienne.
    config(['app.release' => 'abc1234']);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('release', 'abc1234');
});
