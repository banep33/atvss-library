# Golden test normalizacije — Python strana pariteta.
#
# Korpus (resources/normalization/normalization-golden.json) je snimljen
# ponašanjem koda PRE prelaska na zajedničku mapu (normalization-map.json).
# PHP stranu istog korpusa proverava tests/Unit/NormalizacijaParitetTest.php —
# zajedno dokazuju da PHP i Python normalizuju IDENTIČNO.
#
# Pokretanje (iz rag_service/):  venv\Scripts\python.exe test_normalizacija.py
# Radi i kroz pytest ako je instaliran.

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import main  # noqa: E402

_GOLDEN_PATH = (
    Path(__file__).resolve().parent.parent
    / "resources" / "normalization" / "normalization-golden.json"
)


def _golden() -> list[dict]:
    korpus = json.loads(_GOLDEN_PATH.read_text(encoding="utf-8"))
    assert isinstance(korpus, list) and korpus, f"prazan/nevalidan golden: {_GOLDEN_PATH}"
    return korpus


def test_cyrillic_to_latin_odgovara_golden_korpusu():
    for slucaj in _golden():
        dobijeno = main.cyrillic_to_latin(slucaj["ulaz"])
        assert dobijeno == slucaj["ocekivano_translit"], (
            f"translit razlika za {slucaj['ulaz']!r}: "
            f"ocekivano {slucaj['ocekivano_translit']!r}, dobijeno {dobijeno!r}"
        )


def test_normalize_odgovara_golden_korpusu():
    for slucaj in _golden():
        dobijeno = main.normalize(slucaj["ulaz"])
        assert dobijeno == slucaj["ocekivano_normalize"], (
            f"normalize razlika za {slucaj['ulaz']!r}: "
            f"ocekivano {slucaj['ocekivano_normalize']!r}, dobijeno {dobijeno!r}"
        )


if __name__ == "__main__":
    test_cyrillic_to_latin_odgovara_golden_korpusu()
    test_normalize_odgovara_golden_korpusu()
    print(f"OK — golden paritet ({len(_golden())} slucajeva, mapa: {main._NORM_MAP_PATH})")
