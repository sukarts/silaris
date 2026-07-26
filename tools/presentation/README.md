# Présentation commerciale

Génère `SILARIS-presentation.pptx` (30 diapositives, 16:9) à partir de captures
réelles de l'application, jamais de maquettes.

## 1. Captures

Le jeu de démonstration doit être chargé et l'API accessible. Le serveur de
développement recompile chaque route à la première visite et fait expirer les
attentes : on capture donc contre un build de production.

```bash
cd apps/web
pnpm build && PORT=3100 pnpm start &
CAPTURE_DIR=$PWD/captures E2E_BASE_URL=http://localhost:3100 \
  pnpm exec playwright test --config playwright.capture.config.ts
```

Vingt écrans sont produits en 3000×1880 dans `apps/web/captures/`, dossier
ignoré par git.

## 2. Diapositives

```bash
python3 tools/presentation/build_deck.py
```

`bon-livraison.png` est la seule image versionnée : c'est le rendu d'un PDF
produit par l'application, pas une capture d'écran.

## Vérifier le rendu

python-pptx ne rend rien. Pour relire les diapositives sans PowerPoint :

```bash
osascript -e 'on run argv
  tell application "Keynote"
    set doc to open (POSIX file (item 1 of argv))
    export doc to (POSIX file (item 2 of argv)) as slide images
    close doc saving no
  end tell
end run' "$PWD/SILARIS-presentation.pptx" "$PWD/slides"
```
