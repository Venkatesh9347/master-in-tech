interface SpeakerCardProps {
  name: string;
  designation: string;
  image?: string;
}

export default function SpeakerCard({ name, designation, image }: SpeakerCardProps) {
  return (
    <div className="text-center">
      <div className="w-32 h-32 mx-auto mb-4 rounded-full overflow-hidden bg-gradient-to-br from-blue-100 to-blue-50 flex items-center justify-center border-4 border-white shadow-lg">
        {image ? (
          <img src={image} alt={name} className="w-full h-full object-cover" />
        ) : (
          <div className="text-4xl">👤</div>
        )}
      </div>
      <h3 className="text-xl font-bold text-slate-900 mb-1">{name}</h3>
      <p className="text-sm text-slate-600">{designation}</p>
    </div>
  );
}
