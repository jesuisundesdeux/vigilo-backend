### Configuration Twitter

- Créer un compte Twitter dédié à votre scope. Par soucis de cohérence avec toutes les autres instances, il est recommandé d'utiliser le modèle @VigiloVille. Exemples : [VigiloTroyes](https://twitter.com/VigiloTroyes), [VigiloMtp34](https://twitter.com/VigiloMtp34), [VigiloValleeSud](https://twitter.com/VigiloValleeSud), [VigiloMetz](https://twitter.com/VigiloMetz)

Pour la suite, tout se passe en Anglais.

- Se rendre sur https://developer.twitter.com et se connecter avec votre compte Twitter.
- Cliquer sur « Apply » en haut à droite (Apply for a developer account)
- Choisir « Doing something else »
- Choisir « France » à la réponse « What country do you live in? »
- Choisir le nom de votre association dans « What would you like us to call you? »
- Sur la page « How will you use the Twitter API or Twitter data? », renseignez le texte suivant dans le champ « In your words »

> Our application is called Vigilo and is a service allowing users to post observations from the street (badly parked cars, dangers on the road, infrastructure issues, etc.) through a mobile application.
>
> We want to make a Twitter account that will Tweet programmatically content from our Vigilo server.
> When a user post a new observation on our application and when the observation is moderated, our server will automatically tweet the observation (picture + text).
>
> Then Tweeter users can interact with those tweets and retweet them. Nobody will access Twitter data. It's a one-way broadcast from our application.

- Répondre No à toutes les questions « The specifics »
- Cliquer sur « Looks good! »
- Cocher les conditions d'utilisation puis cliquer sur « Accept » puis « Submit Application »

Une fois que votre compte est validé

- Retourner sur https://developer.twitter.com et aller sur « Create an app »
- Dans « App name » choisissez « Vigilo Votre Ville / agglo »
- Dans « Application description » renseigner le texte suivant

> Vigilo is a service allowing users to post observations from the street (badly parked cars, dangers on the road, infrastructure issues, etc.) through a mobile application.

- Dans « Website URL », renseigner le site web associé à votre association.

- Dans « Tell us how this app will be used », renseigner le texte suivant

> Our Twitter account will Tweet programmatically content from our server. When a user posts a new observation on our application and when the observation is moderated, our server will automatically tweet the observation (picture + text). Then Tweeter users can interact with those tweets and retweet them. Nobody will access Twitter data. It's a one-way broadcast from our application.

- Cliquer sur « Create » et encore « Create » pour valider.
- Normalement votre application devrait être créée à ce stade.
- Rendez-vous dans l'onglet « Keys and tokens » et récupérez l'API key (consumer) et l'API secret key (consumersecret)
- Créez un Access token dans « Access token & access token secret » et récupérez l'Access token (accesstoken) et l'Access token secret (accesstokensecret)
- Renseignez enfin ces 4 clés dans votre configuration Vigilo.
