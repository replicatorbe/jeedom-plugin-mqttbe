# Licences des bibliothèques embarquées

Le plugin `mqttbe` est distribué sous **AGPL v3**. Les trois bibliothèques
copiées dans ce dossier sont toutes sous **licence MIT**. Leurs textes intégraux
sont conservés à côté des sources, comme la licence l'exige, et ne doivent pas
être supprimés à la montée de version.

| Bibliothèque | Version | Licence | Titulaire | Texte |
|---|---|---|---|---|
| [php-mqtt/client](https://github.com/php-mqtt/client) | v2.3.2 | MIT | Marvin Mall | `php-mqtt/client/LICENSE.md` |
| [psr/log](https://github.com/php-fig/log) | 3.0.2 | MIT | PHP Framework Interoperability Group (2012) | `psr/log/LICENSE` |
| [myclabs/php-enum](https://github.com/myclabs/php-enum) | 1.8.5 | MIT | My C-Labs (2015) | `myclabs/php-enum/LICENSE` |

## Compatibilité avec l'AGPL du plugin

La licence MIT est permissive : elle autorise l'usage, la copie, la modification
et la redistribution, y compris dans un travail dérivé placé sous une autre
licence, à la seule condition de conserver l'avis de droit d'auteur et le texte
de la licence. Elle n'impose en retour aucune contrainte sur le reste du code.

Elle est donc compatible avec l'AGPL v3, et dans ce sens-là seulement : du code
MIT peut être intégré à un ensemble distribué sous AGPL. L'inverse n'est pas
vrai — on ne peut pas reverser du code du plugin dans ces bibliothèques sans
changer sa licence. La Free Software Foundation classe explicitement la licence
MIT (« Expat ») parmi les licences libres permissives compatibles GPL, et
l'AGPL v3 hérite de cette compatibilité.

Concrètement :

- l'ensemble distribué (plugin + bibliothèques) reste sous AGPL v3 ;
- chaque fichier de ces trois bibliothèques reste sous MIT et sous le droit
  d'auteur de son titulaire, en-têtes compris ;
- les fichiers `LICENSE` accompagnent obligatoirement toute redistribution du
  plugin, y compris via le marché Jeedom ;
- ne pas modifier les sources embarquées : une modification ferait du fichier
  une œuvre dérivée à documenter, alors que la copie à l'identique se contente
  du fichier de licence d'origine (voir `VENDOR.md`).
