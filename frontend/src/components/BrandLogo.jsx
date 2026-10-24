// Official Yorilife Sciences logo (original file from yorilife.in, unmodified).
// Intrinsic size is 792x365; CSS fixes the height and lets width follow the ratio.
const LOGO_SRC = `${import.meta.env.BASE_URL}assets/images/yorilife-logo.png`

export default function BrandLogo({ className = 'app-brand__logo' }) {
  return (
    <img
      src={LOGO_SRC}
      alt="Yorilife Sciences"
      width="792"
      height="365"
      className={className}
    />
  )
}
