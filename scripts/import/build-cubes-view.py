import sqlalchemy
import cubes

# Config
model_path = "model.json"
db_connect = "mysql://datashuttle:datashuttle@localhost:3306/datashuttle"
cube_name = "mortality"

# Load cube
model = cubes.model_from_path(model_path)
engine = sqlalchemy.create_engine(db_connect)
connection = engine.connect()
cube = model.cube(cube_name)

# Build view
builder = cubes.backends.SQLDenormalizer(cube, connection)
builder.create_view("view_" + cube_name, materialize=True)

print "Successfully created view\n"
