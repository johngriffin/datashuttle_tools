import math

def os2latlng(E, N):
    OSGB_F0  = 0.9996012717
    N0       = -100000.0
    E0       = 400000.0

    a        = 6377563.396
    b        = 6356256.909
    eSquared = ab2ecc(a, b)

    phi0     = math.radians(49.0)
    lambda0  = math.radians(-2.0)
    phi      = 0.0
    lmb      = 0.0
    n        = (a - b) / (a + b)
    M        = 0.0
    phiPrime = ((N - N0) / (a * OSGB_F0)) + phi0

    while True:
        M = (b * OSGB_F0) \
            * (((1 + n + ((5.0 / 4.0) * n * n) + ((5.0 / 4.0) * n * n * n)) \
                * (phiPrime - phi0)) \
                - (((3 * n) + (3 * n * n) + ((21.0 / 8.0) * n * n * n)) \
                    * math.sin(phiPrime - phi0) \
                    * math.cos(phiPrime + phi0)) \
                + ((((15.0 / 8.0) * n * n) + ((15.0 / 8.0) * n * n * n)) \
                    * math.sin(2.0 * (phiPrime - phi0)) \
                    * math.cos(2.0 * (phiPrime + phi0))) \
                - (((35.0 / 24.0) * n * n * n) \
                    * math.sin(3.0 * (phiPrime - phi0)) \
                    * math.cos(3.0 * (phiPrime + phi0))))

        phiPrime += (N - N0 - M) / (a * OSGB_F0)
        if ((N - N0 - M) >= 0.001):
            break

    v = a * OSGB_F0 * math.pow(1.0 - eSquared * sinSquared(phiPrime), -0.5)
    rho = a \
      * OSGB_F0 \
      * (1.0 - eSquared) \
      * math.pow(1.0 - eSquared * sinSquared(phiPrime), -1.5)

    etaSquared = (v / rho) - 1.0

    VII = math.tan(phiPrime) / (2 * rho * v)
    VIII = (math.tan(phiPrime) / (24.0 * rho * math.pow(v, 3.0))) \
        * (5.0 \
            + (3.0 * tanSquared(phiPrime)) \
            + etaSquared \
            - (9.0 * tanSquared(phiPrime) * etaSquared))
    IX = (math.tan(phiPrime) / (720.0 * rho * math.pow(v, 5.0))) \
        * (61.0 \
            + (90.0 * tanSquared(phiPrime)) \
            + (45.0 * tanSquared(phiPrime) * tanSquared(phiPrime)))
    X = sec(phiPrime) / v
    XI = (sec(phiPrime) / (6.0 * v * v * v)) \
      * ((v / rho) + (2 * tanSquared(phiPrime)))
    XII = (sec(phiPrime) / (120.0 * math.pow(v, 5.0))) \
        * (5.0 \
            + (28.0 * tanSquared(phiPrime)) \
            + (24.0 * tanSquared(phiPrime) * tanSquared(phiPrime)))
    XIIA = (sec(phiPrime) / (5040.0 * math.pow(v, 7.0))) \
        * (61.0 \
            + (662.0 * tanSquared(phiPrime)) \
            + (1320.0 * tanSquared(phiPrime) * tanSquared(phiPrime)) \
            + (720.0
                * tanSquared(phiPrime) \
                * tanSquared(phiPrime) \
                * tanSquared(phiPrime)))

    phi = phiPrime \
        - (VII * math.pow(E - E0, 2.0)) \
        + (VIII * math.pow(E - E0, 4.0)) \
        - (IX * math.pow(E - E0, 6.0))

    lmb = lambda0 \
        + (X * (E - E0)) \
        - (XI * math.pow(E - E0, 3.0)) \
        + (XII * math.pow(E - E0, 5.0)) \
        - (XIIA * math.pow(E - E0, 7.0))

    return OSGB36toWGS84(math.degrees(phi), math.degrees(lmb))

def OSGB36toWGS84(lat, lng):
    a        = 6377563.396
    b        = 6356256.909
    eSquared = ab2ecc(a, b)

    phi = math.radians(lat)
    lmb = math.radians(lng)

    v = a / (math.sqrt(1 - eSquared * sinSquared(phi)))
    H = 0
    x = (v + H) * math.cos(phi) * math.cos(lmb)
    y = (v + H) * math.cos(phi) * math.sin(lmb)
    z = ((1 - eSquared) * v + H) * math.sin(phi)

    tx = 446.448
    ty = -124.157
    tz = 542.060
    s  = -0.0000204894
    rx = math.radians(0.00004172222)
    ry = math.radians(0.00006861111)
    rz = math.radians(0.00023391666)

    xB = tx + (x * (1 + s)) + (-rx * y)     + (ry * z)
    yB = ty + (rz * x)      + (y * (1 + s)) + (-rx * z)
    zB = tz + (-ry * x)     + (rx * y)      + (z * (1 + s))

    a        = 6378137.000
    b        = 6356752.3141
    eSquared = ab2ecc(a, b)

    lambdaB = math.degrees(math.atan(yB / xB))
    p = math.sqrt((xB * xB) + (yB * yB))
    phiN = math.atan(zB / (p * (1 - eSquared)))
    for i in xrange(1,10):
        v = a / (math.sqrt(1 - eSquared * sinSquared(phiN)))
        phiN1 = math.atan((zB + (eSquared * v * math.sin(phiN))) / p)
        phiN = phiN1

    phiB = math.degrees(phiN)

    return (phiB, lambdaB)

def ab2ecc(a, b):
    return ((a * a) - (b * b)) / (a * a)

def sinSquared(x):
    return math.sin(x) * math.sin(x)

def tanSquared(x):
    return math.tan(x) * math.tan(x)

def sec(x):
    return 1.0 / math.cos(x)
