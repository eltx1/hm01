const SELLERS_JSON_ROUTES = new Map([
  ["/sellers.json", "https://cdn.horusmedia.net/sellers.json"],
  ["/supply/sellers.json", "https://cdn.horusmedia.net/supply/sellers.json"],
]);

export default {
  async fetch(request, env) {
    const { pathname } = new URL(request.url);
    const sellersJsonTarget = SELLERS_JSON_ROUTES.get(pathname);

    if (sellersJsonTarget) {
      return new Response(null, {
        status: 302,
        headers: {
          Location: sellersJsonTarget,
          "Cache-Control": "public, max-age=300",
        },
      });
    }

    return env.ASSETS.fetch(request);
  },
};
