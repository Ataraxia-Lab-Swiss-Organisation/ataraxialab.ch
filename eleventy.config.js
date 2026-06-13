module.exports = function(eleventyConfig) {
  eleventyConfig.addPassthroughCopy("src/robots.txt");
  eleventyConfig.addPassthroughCopy("src/css");
  eleventyConfig.addPassthroughCopy("src/images");
  eleventyConfig.addPassthroughCopy("src/documents");
  eleventyConfig.addPassthroughCopy("src/fonts");

  // Maillage interne tutoriels — 12.06.2026
  // Retourne jusqu'à 3 tutoriels liés : priorité à la même catégorie,
  // complété par d'autres catégories si besoin, hors article courant.
  eleventyConfig.addFilter("articlesLies", function(tutoriels, categorie, currentSlug) {
    const memeCategorie = tutoriels.filter(t => t.categorie === categorie && t.slug !== currentSlug);
    const autres = tutoriels.filter(t => t.categorie !== categorie && t.slug !== currentSlug);
    const selection = memeCategorie.slice(0, 3);
    if (selection.length < 3) {
      selection.push(...autres.slice(0, 3 - selection.length));
    }
    return selection;
  });

  return {
    dir: {
      input: "src",
      output: "_site",
      data: "_data"
    }
  };
};
