# Translator feedback for automatic translation
This GSoC 2026 project uses translator feedback to improve automatic domain specific translations, focussing on Joomla specific translations, using the experience of our translation teams. This project will work by capturing human corrections and injecting them into future LLM prompts via a decoupled RAG architecture. It will be delivered as a separate extension package.

* Contributor: Krishna Gandhi.
* Mentors: Herman Peeren, Charvi Mehra, Stefan Wendhausen.

## Documentation

* [GSoC 2026 final work product](docs/gsoc-2026-work-product.md) - what was built, what was merged upstream, and what is left to do.
* [User guide](docs/user-guide.md) - setting the component up and using it.
* [Glossary](docs/glossary.md) - the vocabulary this project uses in a narrow sense.
* [Content type map](docs/contenttypes.md) - the keys that describe a translatable content type.
* [Writing a translation plugin](docs/translation-plugin.md) - adding support for another provider.
