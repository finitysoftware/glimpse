/**
 * The name of a page in the browser tab and history. React puts the <title> into the document head before the titles
 * already there (index.html's "Glimpse" included), so the page shown last names the tab, and its title leaves with it.
 */
export function PageTitle({ children }: { children: string }) {
  return <title>{`${children} · Glimpse`}</title>
}
