# PlateBook Native

Dette er en React Native/Expo app til PlateBook.
Appen bruger Laravel som API-backend med native token-login og React Native-skærme for login, kalender, ydelser og profil.

## Kør appen

```bash
cd native-app
npm install
npm run start
```

Åbn derefter appen i Expo Go, iOS simulator eller Android emulator.

## API base URL

Appens API-adresse kan sættes med `EXPO_PUBLIC_API_BASE_URL`.

Opret evt. `native-app/.env` lokalt:

```env
EXPO_PUBLIC_API_BASE_URL=https://ditdomæne.dk
```

Hvis variablen ikke er sat, bruger appen den lokale udviklingsadresse fra `app.json`.
Til produktion skal værdien være dit Laravel Cloud-domæne uden `/api`, f.eks.
`https://ditdomæne.dk`.

## Kør på iPhone med Xcode

iOS-projektet er genereret i `ios/`, og CocoaPods er installeret.

1. Sørg for at `EXPO_PUBLIC_API_BASE_URL` peger på dit Laravel Cloud-domæne.
2. Sørg for at API'et virker på `https://ditdomæne.dk/api/native/login`.
3. Start Metro:

```bash
cd native-app
npm run start -- --localhost
```

4. Åbn workspace i Xcode:

```bash
open ios/PlateBook.xcworkspace
```

5. Vælg din iPhone som destination.
6. Vælg dit Apple Developer Team under `Signing & Capabilities`.
7. Tryk Run.

Hvis Pods skal geninstalleres på denne Mac:

```bash
npm run ios:pods
```

## Produktion

Når Laravel Cloud er deployet, skal appen bygges med:

```bash
cd native-app
EXPO_PUBLIC_API_BASE_URL=https://ditdomæne.dk npm run start -- --localhost
```

Åbn derefter `ios/PlateBook.xcworkspace` i Xcode og kør appen på iPhone.

## Videre plan

Denne version bruger native UI til de første centrale flows.
Næste naturlige skridt er at udvide API'et med opret/rediger booking, gennemfør/annuller booking, tilgængelighed, brugere og indstillinger.
