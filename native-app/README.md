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
EXPO_PUBLIC_EAS_PROJECT_ID=din-eas-project-id
```

Hvis variablen ikke er sat, bruger appen produktionsadressen fra `app.json`.
Værdien skal være dit Laravel Cloud-domæne uden `/api`, f.eks. `https://ditdomæne.dk`.

`EXPO_PUBLIC_EAS_PROJECT_ID` bruges til Expo push-notifikationer. Uden den kan appen stadig køre,
men den registrerer ikke push-token.

## Kør på iPhone med Xcode under udvikling

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

Når appen køres sådan i Debug fra Xcode, kræver den Metro på din Mac. Den kan derfor ikke bruges
uden for samme netværk, selvom API'et ligger online.

Hvis Pods skal geninstalleres på denne Mac:

```bash
npm run ios:pods
```

## Push-notifikationer

Appen beder om notifikationstilladelse efter login og sender en Expo push-token til Laravel.
Laravel gemmer token på den aktive native app session og sender en push-besked til behandleren,
når der oprettes en ny booking via native app, intern booking eller public booking.

For at push virker på iPhone skal appen bygges på en fysisk enhed med push credentials.
Med Expo betyder det normalt, at projektet har en EAS project id og iOS push credentials.

## Produktion / uden WiFi

Hvis appen skal kunne åbne væk fra din Mac og uden WiFi, skal den installeres som Release-build
eller Archive, så JavaScript-bundlen ligger inde i appen:

1. Åbn `ios/PlateBook.xcworkspace` i Xcode.
2. Vælg `Product -> Scheme -> Edit Scheme`.
3. Vælg `Run` i venstre side.
4. Sæt `Build Configuration` til `Release`.
5. Vælg din iPhone og tryk Run.

I Release-build skal Metro ikke køre.

## Videre plan

Denne version bruger native UI til de første centrale flows.
Næste naturlige skridt er at udvide API'et med opret/rediger booking, gennemfør/annuller booking, tilgængelighed, brugere og indstillinger.
